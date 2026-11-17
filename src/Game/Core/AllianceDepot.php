<?php

declare(strict_types=1);

namespace App\Game\Core;

use App\Game\Enum\MessageType;
use App\Game\Enum\Mission;

/**
 * Alliance Depot (building 34): the owner of a planet with a depot can extend the stay of friendly fleets that hold
 * (mission hold, status FLEET_HOLD) at that planet, paying deuterium from the planet.
 *
 * Rules: per supply 1 .. depot level hours; the stay left after the supply may not exceed the longest hold duration
 * the fleet page offers (FleetMissionRules::HOLD_STAY_HOURS, 32 h); the cost is hours times the hourly holding cost of
 * the fleet, ceil(sum of ship count times base fuel consumption / 10) deuterium. Only fleets of other players that are
 * in orbit (arrived, stay not over) and not being processed by the event handler can be supplied.
 *
 * A holding fleet's event (fleet_event.time) is its stay end (MissionFunctions::setState(FLEET_HOLD)), so a supply moves
 * fleets.stay_time and fleets.return_time by the same seconds and sets the event time to the new stay_time.
 *
 * Collaborators: Database (PLANETS, FLEETS, FLEETS_EVENT, LOG_FLEETS, USERS_SETTINGS, MESSAGES), Singleton (pricelist,
 * runtime time), ResourcePersistence (debit), PlayerMessenger (message), Language.
 */
class AllianceDepot
{
    use GameUtilities;

    /** divisor of the summed base fuel consumption (OGame rule) */
    private const HOLD_COST_DIVISOR = 10;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /** Longest stay (hours) a fleet may have left after a supply. */
    public function maxRemainingStayHours(): int
    {
        return max(FleetMissionRules::HOLD_STAY_HOURS);
    }

    /**
     * Deuterium per hour a fleet costs while it is supplied: ceil(sum of count times base fuel consumption / 10).
     * Base consumption is the pricelist consumption of the drive the ship is built with (the first of consumption1..3
     * that is set), independent of the owner's drive and fuel techs, so the price is the same for every fleet.
     *
     * With the universe setting depot_cost_real_consumption (default on) and the fleet owner given, the fleet's actual
     * consumption (the owner's drive and fuel efficiency techs, as for a flight) is used instead of the base one.
     *
     * @param array<int|string, int|float|string> $ships ship id => count
     */
    public function hourlyCost(array $ships, ?int $ownerId = null): int
    {
        $elements = $this->elements();
        $real = $ownerId !== null && $this->configs->get()->depot_cost_real_consumption
            ? $this->realConsumptions($ships, $ownerId)
            : null;
        $sum = 0.0;
        foreach ($ships as $shipId => $count) {
            $per = $real[$shipId] ?? $elements->find((int) $shipId)?->baseConsumption() ?? 0.0;
            $sum += (float) $count * $per;
        }

        return (int) ceil($sum / self::HOLD_COST_DIVISOR);
    }

    /**
     * Consumption per ship of each type for the owner's techs.
     *
     * @param array<int|string, int|float|string> $ships ship id => count
     * @return array<int|string, float> ship id => consumption of one ship
     */
    private function realConsumptions(array $ships, int $ownerId): array
    {
        $owner = $this->db->selectSingle('SELECT * FROM %%USERS%% WHERE `id` = :id;', [':id' => $ownerId]);
        if (!is_array($owner)) {
            return [];
        }
        $info = $this->fleetFunctions()->getFleetShipInfo($ships, $owner);

        return array_map(static fn(array $row): float => (float) $row['consumption'], $info);
    }

    /**
     * Hours the owner may add to a fleet now: 1 .. depot level, not beyond the stay cap. 0 when none.
     */
    public function allowedHours(int $depotLevel, int $stayTime, int $now): int
    {
        $room = intdiv($this->maxRemainingStayHours() * 3600 - max(0, $stayTime - $now), 3600);

        return max(0, min($depotLevel, $room));
    }

    /**
     * Friendly fleets that hold at the planet right now (stay not over), soonest end first, with the owner's name, the
     * ship list, the hourly cost and the hours that can be added.
     *
     * @return list<array<string, mixed>>
     */
    public function holdingFleets(int $planetId, int $depotOwnerId, int $depotLevel): array
    {
        $now = $this->state->runtime->timestamp;
        $rows = $this->db->select(
            'SELECT f.`id`, f.`owner`, f.`ships`, f.`stay_time`, f.`return_time`, u.`username`
            FROM %%FLEETS%% f INNER JOIN %%USERS%% u ON u.`id` = f.`owner`
            WHERE f.`end_id` = :planetId AND f.`mission` = :mission AND f.`status` = :status
              AND f.`owner` != :ownerId AND f.`stay_time` > :now
            ORDER BY f.`stay_time` ASC, f.`id` ASC;',
            [
                ':planetId' => $planetId,
                ':mission' => Mission::Hold->value,
                ':status' => FLEET_HOLD,
                ':ownerId' => $depotOwnerId,
                ':now' => $now,
            ],
        );

        $fleets = [];
        foreach ($rows as $row) {
            $ships = $this->fleetFunctions()->unserialize((string) $row['ships']);
            $fleets[] = [
                'id' => (int) $row['id'],
                'owner' => (int) $row['owner'],
                'username' => $row['username'],
                'ships' => $ships,
                'stayTime' => (int) $row['stay_time'],
                'hourlyCost' => $this->hourlyCost($ships, (int) $row['owner']),
                'maxHours' => $this->allowedHours($depotLevel, (int) $row['stay_time'], $now),
            ];
        }

        return $fleets;
    }

    /**
     * Extends the stay of one fleet by $hours and debits the deuterium from the depot planet, in one transaction
     * (rows locked in the fixed order planet, then fleet with its event row; everything is checked again under the locks).
     *
     * @return array{fleetId: int, fleetOwner: int, hours: int, cost: int, stayTime: int, galaxy: int, system: int, planet: int, type: int}
     * @throws AllianceDepotRefused when the supply is not possible
     */
    public function extend(int $planetId, int $depotOwnerId, int $fleetId, int $hours): array
    {
        $now = $this->state->runtime->timestamp;

        $result = $this->db->transactional(function () use ($planetId, $depotOwnerId, $fleetId, $hours, $now): array {
            $planet = $this->db->selectSingle(
                'SELECT `id`, `id_owner`, `ally_deposit`, `deuterium` FROM %%PLANETS%% WHERE `id` = :id FOR UPDATE;',
                [':id' => $planetId],
            );
            if (empty($planet) || (int) $planet['id_owner'] !== $depotOwnerId) {
                throw new AllianceDepotRefused('depot_err_not_owner');
            }
            $level = (int) $planet['ally_deposit'];
            if ($level < 1) {
                throw new AllianceDepotRefused('depot_err_no_depot');
            }
            if ($hours < 1 || $hours > $level) {
                throw new AllianceDepotRefused('depot_err_hours', [$level]);
            }

            $fleet = $this->db->selectSingle(
                'SELECT f.*, fe.`lock` AS event_lock
                FROM %%FLEETS%% f INNER JOIN %%FLEETS_EVENT%% fe ON fe.`fleetID` = f.`id`
                WHERE f.`id` = :id FOR UPDATE;',
                [':id' => $fleetId],
            );
            if (
                empty($fleet)
                || (int) $fleet['mission'] !== Mission::Hold->value
                || (int) $fleet['status'] !== FLEET_HOLD
                || (int) $fleet['end_id'] !== $planetId
                || (int) $fleet['owner'] === $depotOwnerId
                || (int) $fleet['stay_time'] <= $now
                || $fleet['event_lock'] !== null
            ) {
                throw new AllianceDepotRefused('depot_err_fleet');
            }

            $stayTime = (int) $fleet['stay_time'];
            if ($hours > $this->allowedHours($level, $stayTime, $now)) {
                throw new AllianceDepotRefused('depot_err_max_stay', [$this->maxRemainingStayHours()]);
            }

            $cost = $hours * $this->hourlyCost($this->fleetFunctions()->unserialize((string) $fleet['ships']), (int) $fleet['owner']);
            if ((float) $planet['deuterium'] < $cost) {
                throw new AllianceDepotRefused('depot_err_deuterium', [$cost]);
            }

            $this->gameUtility(ResourcePersistence::class)->removeResources($planetId, [RESOURCE_DEUT => $cost]);
            $this->moveStay($fleetId, $stayTime, (int) $fleet['return_time'], $hours * 3600);

            return [
                'fleetId' => $fleetId,
                'fleetOwner' => (int) $fleet['owner'],
                'hours' => $hours,
                'cost' => $cost,
                'stayTime' => $stayTime + $hours * 3600,
                'galaxy' => (int) $fleet['end_galaxy'],
                'system' => (int) $fleet['end_system'],
                'planet' => (int) $fleet['end_planet'],
                'type' => (int) $fleet['end_type'],
            ];
        });

        // the request's planet copy mirrors the debit (the transaction may have run more than once)
        if (($this->state->PLANET['id'] ?? 0) == $planetId) {
            $this->state->PLANET[$this->elements()->column(RESOURCE_DEUT)] -= $result['cost'];
        }

        return $result;
    }

    /** Moves stay and return time of the fleet (and its log copy) and the event time (= stay end while holding). */
    private function moveStay(int $fleetId, int $stayTime, int $returnTime, int $seconds): void
    {
        $newStay = $stayTime + $seconds;
        $newReturn = $returnTime + $seconds;

        $this->db->update('UPDATE %%FLEETS%% SET `stay_time` = :stay, `return_time` = :return WHERE `id` = :id;', [
            ':stay' => $newStay,
            ':return' => $newReturn,
            ':id' => $fleetId,
        ]);
        $this->db->update('UPDATE %%FLEETS_EVENT%% SET `time` = :time WHERE `fleetID` = :id;', [
            ':time' => $newStay,
            ':id' => $fleetId,
        ]);
        $this->db->update(
            'UPDATE %%LOG_FLEETS%% SET `stay_time` = :stay, `stay_time_formated` = :stayFormated,
                `return_time` = :return, `return_time_formated` = :returnFormated
            WHERE `id` = :id;',
            [
                ':stay' => $newStay,
                ':stayFormated' => $this->db->formatDate($newStay),
                ':return' => $newReturn,
                ':returnFormated' => $this->db->formatDate($newReturn),
                ':id' => $fleetId,
            ],
        );
    }

    /**
     * Tells the fleet owner (in his language) that his fleet was supplied. Call after extend() succeeded.
     *
     * @param array{fleetId: int, fleetOwner: int, hours: int, cost: int, stayTime: int, galaxy: int, system: int, planet: int, type: int} $extension
     * @param string $depotOwnerName name of the supplying player
     */
    public function notifyFleetOwner(array $extension, string $depotOwnerName): void
    {
        $language = $this->db->selectSingle(
            'SELECT `lang` FROM %%USERS_SETTINGS%% WHERE `user_id` = :id;',
            [':id' => $extension['fleetOwner']],
            'lang',
        );
        $LNG = new Language($this->configs, $language ?: null);
        $LNG->includeData(['L18N', 'FLEET', 'TECH', 'CUSTOM']);

        $coords = '<a href="game/galaxy/' . $extension['galaxy'] . '/' . $extension['system']
            . '">[' . $extension['galaxy'] . ':' . $extension['system'] . ':' . $extension['planet'] . ']</a>';

        $this->playerMessenger()->sendMessage(
            $extension['fleetOwner'],
            0,
            $LNG['sys_mess_tower'],
            MessageType::System->value,
            $LNG['depot_msg_subject'],
            $LNG->format('depot_msg_text', $coords, $depotOwnerName, $extension['hours']),
            $this->state->runtime->timestamp,
            1,
            (int) ($this->state->USER['universe'] ?? 1),
        );
    }
}
