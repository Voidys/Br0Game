<?php

namespace App\Game\Core;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;

/**
 * Sends and recalls fleets: takes ships and cargo off the start planet, writes the flight (fleets, fleet event and
 * fleet log rows) and turns a running flight (or a whole ACS group) around.
 *
 * Collaborators: Database (PLANETS, FLEETS, FLEETS_EVENT, LOG_FLEETS, AKS, USERS_TO_ACS), AdvancedStatsHandler (fuel
 * stat), MarketManager (reference ratios for the fleet log), Universe, Singleton (request time).
 */
class FleetLauncher
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Number of the user's flights: all except $fleetMission (default expeditions) or, with $thisMission, only those.
     * Used for the fleet slot and expedition slot limits.
     */
    public function getCurrentFleets(int $userId, int $fleetMission = 10, bool $thisMission = false): int
    {
        if ($thisMission) {
            $sql = 'SELECT COUNT(*) as state
                FROM %%FLEETS%%
                WHERE `owner` = :userId AND `mission` = :fleetMission;';
        } else {
            $sql = 'SELECT COUNT(*) as state
                FROM %%FLEETS%%
                WHERE `owner` = :userId AND `mission` != :fleetMission;';
        }

        $ActualFleets = $this->db->selectSingle($sql, [
            ':userId' => $userId,
            ':fleetMission' => $fleetMission,
        ]);
        return $ActualFleets['state'];
    }

    /**
     * Recalls a flight of the user (or, for an ACS attack with a group, the whole group): flips it to FLEET_RETURN,
     * sets the return time to "now + time already flown" (hold/trade missions that are already holding: the remaining
     * outward duration instead), cancels the ACS group row and updates the fleet log.
     *
     * Side effects: DB updates on FLEETS/FLEETS_EVENT/LOG_FLEETS, deletes AKS/USERS_TO_ACS rows of the group.
     * Not recallable: unknown or cannotCancel flights, foreign flights, flights already returning, missiles.
     *
     * @return bool false when the recall is not allowed
     */
    public function sendFleetBack(array $USER, int $FleetID): bool
    {
        $db = $this->db;

        $fleetResult = $this->loadRecallableFleet($FleetID);

        if (empty($fleetResult)) {
            return false;
        }

        if ($fleetResult['owner'] != $USER['id'] || $fleetResult['status'] == FLEET_RETURN) {
            return false;
        }

        // Interplanetary missiles cannot be recalled (their return event does nothing)
        if ($fleetResult['mission'] == MISSION_MISSILE) {
            return false;
        }

        $sqlWhere = '`id`';

        if ($fleetResult['mission'] == MISSION_ATTACK && $fleetResult['group'] != 0) {
            $sql = 'SELECT COUNT(*) as state FROM %%USERS_TO_ACS%% WHERE `acsID` = :acsId;';
            $isInGroup = $db->selectSingle($sql, [
                ':acsId' => $fleetResult['group'],
            ], 'state');

            if ($isInGroup) {
                $sql = 'DELETE %%AKS%%, %%USERS_TO_ACS%%
                    FROM %%AKS%%
                    LEFT JOIN %%USERS_TO_ACS%% ON `acsID` = %%AKS%%.id
                    WHERE %%AKS%%.id = :acsId;';

                $db->delete($sql, [
                    ':acsId' => $fleetResult['group'],
                ]);

                $FleetID = $fleetResult['group'];
                $sqlWhere = '`group`';
            }
        }

        if (
            ($fleetResult['mission'] == MISSION_HOLD || $fleetResult['mission'] == MISSION_TRADE)
            && $fleetResult['status'] == FLEET_HOLD
        ) {
            $endTime = ($fleetResult['arrival_time'] - $fleetResult['start_time']) + $this->state->runtime->timestamp;
        } else {
            $endTime = ($this->state->runtime->timestamp - $fleetResult['start_time']) + $this->state->runtime->timestamp;
        }

        // An ACS group is recalled as a whole, but every fleet gets its return time from its own start time
        $recallFleets = [$FleetID => $endTime];
        if ($sqlWhere === '`group`') {
            $recallFleets = [];
            $groupFleets = $db->select('SELECT `id`, `start_time` FROM %%FLEETS%% WHERE `group` = :id;', [
                ':id' => $FleetID,
            ]);
            foreach ($groupFleets as $groupFleet) {
                $recallFleets[$groupFleet['id']] = ($this->state->runtime->timestamp - $groupFleet['start_time'])
                    + $this->state->runtime->timestamp;
            }
        }

        foreach ($recallFleets as $recallFleetId => $recallEndTime) {
            $this->markFleetReturning($recallFleetId, $recallEndTime);
        }

        return true;
    }

    /**
     * Reads the recall-relevant columns of a flight that may still be cancelled, with empty columns normalised to 0
     * (status to FLEET_OUTWARD).
     *
     * @return array<string, mixed>|false false/empty when there is no such flight or it cannot be cancelled
     */
    private function loadRecallableFleet(int $FleetID)
    {
        $sql = 'SELECT `start_time`, `arrival_time`, `mission`, `group`, `owner`, `status`
            FROM %%FLEETS%%
            WHERE `id` = :fleetId AND `cannotCancel` = :cannotCancel;';
        $fleetResult = $this->db->selectSingle($sql, [
            ':fleetId' => $FleetID,
            ':cannotCancel' => 0,
        ]);

        if (empty($fleetResult)) {
            return $fleetResult;
        }

        if (empty($fleetResult['start_time'])) {
            $fleetResult['start_time'] = 0;
        }
        if (empty($fleetResult['arrival_time'])) {
            $fleetResult['arrival_time'] = 0;
        }
        if (empty($fleetResult['mission'])) {
            $fleetResult['mission'] = 0;
        }
        if (empty($fleetResult['group'])) {
            $fleetResult['group'] = 0;
        }
        if (empty($fleetResult['owner'])) {
            $fleetResult['owner'] = 0;
        }
        if (empty($fleetResult['status'])) {
            $fleetResult['status'] = FLEET_OUTWARD;
        }

        return $fleetResult;
    }

    /**
     * Writes the return state of a recalled flight to the live tables and to the fleet log.
     */
    private function markFleetReturning(int|string $recallFleetId, int|float $recallEndTime): void
    {
        $db = $this->db;

        $sql = 'UPDATE %%FLEETS%%, %%FLEETS_EVENT%% SET `group` = :fleetGroup,
            `stay_time`     = :endStayTime,
            `return_time`   = :endTime,
            `status`        = :fleetState,
            `hasCanceled`   = :hasCanceled,
            `time`          = :endTime
            WHERE `id` = :id AND `id` = `fleetID`;';

        $db->update($sql, [
            ':id'           => $recallFleetId,
            ':endStayTime'  => $this->state->runtime->timestamp,
            ':endTime'      => $recallEndTime,
            ':fleetGroup'   => 0,
            ':hasCanceled'  => 1,
            ':fleetState'   => FLEET_RETURN,
        ]);

        $sql = 'UPDATE %%LOG_FLEETS%% SET
            `stay_time`             = :endStayTime,
            `stay_time_formated`    = :stayTimeFormated,
            `return_time`           = :endTime,
            `return_time_formated`  = :endTimeFormated,
            `status`                = :fleetState,
            `hasCanceled`           = :hasCanceled
            WHERE `id` = :id;';

        $db->update($sql, [
            ':id' => $recallFleetId,
            ':endStayTime' => $this->state->runtime->timestamp,
            ':endTime' => $recallEndTime,
            ':stayTimeFormated' => $this->db->formatDate($this->state->runtime->timestamp),
            ':endTimeFormated' => $this->db->formatDate($recallEndTime),
            ':fleetState' => FLEET_RETURN,
            ':hasCanceled' => 1,
        ]);
    }

    /**
     * Launches a fleet: subtracts ships, cargo and fuel from the start planet, inserts the flight, its first event
     * (arrival) and the fleet log row (with the current market rate and the cargo value at that rate) and books the
     * fuel in the advanced stats.
     *
     * @param array<int, int|float> $fleetArray [shipId => count]
     * @param array<int, int|float> $fleetResource cargo [901 => metal, 902 => crystal, 903 => deuterium]
     * @param float $consumption fuel (deuterium) taken from the planet on top of the cargo
     * @return int id of the new flight
     */
    public function sendFleet(
        array $fleetArray,
        int $fleetMission,
        int $fleetStartOwner,
        int $fleetStartPlanetID,
        int $fleetStartPlanetGalaxy,
        int $fleetStartPlanetSystem,
        int $fleetStartPlanetPlanet,
        int $fleetStartPlanetType,
        int $fleetTargetOwner,
        int $fleetTargetPlanetID,
        int $fleetTargetPlanetGalaxy,
        int $fleetTargetPlanetSystem,
        int $fleetTargetPlanetPlanet,
        int $fleetTargetPlanetType,
        array $fleetResource,
        int $arrivalTime,
        int $stayTime,
        int $endTime,
        int $fleetGroup = 0,
        int $missileTarget = 0,
        int $cannotCancel = 0,
        float $consumption = 0.0,
    ): int {
        $fleetShipCount = array_sum($fleetArray);
        $fleetTargetPlanetType = ($fleetTargetPlanetID === 0 ? 1 : $fleetTargetPlanetType);
        $fleetData = $this->takeFromStartPlanet($fleetArray, $fleetResource, $fleetStartPlanetID, $consumption);

        $flight = [
            ':fleetGroup' => $fleetGroup,
            ':universe' => $this->universe()->current(),
            ':fleetMission' => $fleetMission,
            ':fleetStartOwner' => $fleetStartOwner,
            ':fleetTargetOwner' => $fleetTargetOwner,
            ':fleetShipCount' => $fleetShipCount,
            ':fleetData' => implode(';', $fleetData),
            ':fleetDataStart' => implode(';', $fleetData),
            ':startTime' => $this->state->runtime->timestamp,
            ':arrivalTime' => $arrivalTime,
            ':stayTime' => $stayTime,
            ':endTime' => $endTime,
            ':fleetStartPlanetID' => $fleetStartPlanetID,
            ':fleetStartPlanetGalaxy' => $fleetStartPlanetGalaxy,
            ':fleetStartPlanetSystem' => $fleetStartPlanetSystem,
            ':fleetStartPlanetPlanet' => $fleetStartPlanetPlanet,
            ':fleetStartPlanetType' => $fleetStartPlanetType,
            ':fleetTargetPlanetID' => $fleetTargetPlanetID,
            ':fleetTargetPlanetGalaxy' => $fleetTargetPlanetGalaxy,
            ':fleetTargetPlanetSystem' => $fleetTargetPlanetSystem,
            ':fleetTargetPlanetPlanet' => $fleetTargetPlanetPlanet,
            ':fleetTargetPlanetType' => $fleetTargetPlanetType,
            ':missileTarget' => $missileTarget,
            ':fuelCost' => $consumption,
            ':fleetResource901' => $fleetResource[901],
            ':fleetResource902' => $fleetResource[902],
            ':fleetResource903' => $fleetResource[903],
            ':cannotCancel' => $cannotCancel,
        ];

        $fleetId = $this->insertFlight($flight);

        $sql = 'INSERT INTO %%FLEETS_EVENT%% SET fleetID	= :fleetId, `time` = :endTime, `universe` = :universe;';
        $this->db->insert($sql, [
            ':fleetId' => $fleetId,
            ':endTime' => $arrivalTime,
            ':universe' => $this->universe()->current(),
        ]);

        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $statsHandler->increment($fleetStartOwner, AdvancedStatsEnum::USED_FUEL, $consumption);

        $this->insertFleetLog($fleetId, $flight, $fleetResource);

        return $fleetId;
    }

    /**
     * Takes the ships, the cargo and the fuel of a launching fleet off the start planet.
     *
     * @param array<int, int|float> $fleetArray [shipId => count]
     * @param array<int, int|float> $fleetResource cargo by resource id; fuel is added to the deuterium column
     * @return list<string> the fleet string parts "shipId,count"
     */
    private function takeFromStartPlanet(array $fleetArray, array $fleetResource, int $fleetStartPlanetID, float $consumption): array
    {
        $resource = $this->state->resource;
        $fleetData = [];

        $params = [':planetId' => $fleetStartPlanetID];

        $planetQuery = [];
        foreach ($fleetArray as $ShipID => $ShipCount) {
            $fleetData[] = $ShipID . ',' . floatToString($ShipCount);
            $planetQuery[] = $resource[$ShipID] . " = " . $resource[$ShipID] . " - :" . $resource[$ShipID];

            $params[':' . $resource[$ShipID]] = floatToString($ShipCount);
        }

        foreach ($fleetResource as $resourceId => $amount) {
            $planetQuery[] = $resource[$resourceId] . " = " . $resource[$resourceId] . " - :" . $resource[$resourceId];
            if ($resourceId == RESOURCE_DEUT && $consumption > 0) {
                $amount += $consumption;
            }
            $params[':' . $resource[$resourceId]] = $amount;
        }

        $sql = 'UPDATE %%PLANETS%% SET ' . implode(', ', $planetQuery) . ' WHERE id = :planetId;';

        $this->db->update($sql, $params);

        return $fleetData;
    }

    /**
     * Inserts the live flight row.
     *
     * @param array<string, mixed> $flight the flight parameters built by sendFleet()
     * @return int the new fleet id
     */
    private function insertFlight(array $flight): int
    {
        $sql = 'INSERT INTO %%FLEETS%% SET
            `group`                 = :fleetGroup,
            `universe`              = :universe,
            `mission`               = :fleetMission,
            `owner`                 = :fleetStartOwner,
            `target_owner`          = :fleetTargetOwner,
            `ship_amount`           = :fleetShipCount,
            `ships`                 = :fleetData,
            `start_ships`           = :fleetDataStart,
            `start_time`            = :startTime,
            `arrival_time`           = :arrivalTime,
            `stay_time`             = :stayTime,
            `return_time`           = :endTime,
            `start_id`              = :fleetStartPlanetID,
            `start_galaxy`          = :fleetStartPlanetGalaxy,
            `start_system`          = :fleetStartPlanetSystem,
            `start_planet`          = :fleetStartPlanetPlanet,
            `start_type`            = :fleetStartPlanetType,
            `end_id`                = :fleetTargetPlanetID,
            `end_galaxy`            = :fleetTargetPlanetGalaxy,
            `end_system`            = :fleetTargetPlanetSystem,
            `end_planet`            = :fleetTargetPlanetPlanet,
            `end_type`              = :fleetTargetPlanetType,
            `missile_target`        = :missileTarget,
            `fuel_cost`             = :fuelCost,
            `resource_metal`        = :fleetResource901,
            `resource_crystal`      = :fleetResource902,
            `resource_deuterium`    = :fleetResource903,
            `cannotCancel`          = :cannotCancel;';

        $this->db->insert($sql, $flight);

        return $this->db->lastInsertId();
    }

    /**
     * Inserts the fleet log row for a new flight, including owner names, the market reference rates at launch time
     * (current_market_rate) and the metal-crystal-deuterium cargo value measured in them.
     *
     * @param array<string, mixed> $flight the flight parameters built by sendFleet()
     * @param array<int, int|float> $fleetResource cargo by resource id
     */
    private function insertFleetLog(int $fleetId, array $flight, array $fleetResource): void
    {
        $db = $this->db;

        $pMarket = new MarketManager($this->db, $this->configs, $this->state);
        $refrates = $pMarket->getReferenceRatios();
        $current_market_rate = MarketManager::formatReferenceRatio($refrates['metal'])
            . ';' . MarketManager::formatReferenceRatio($refrates['crystal'])
            . ';' . MarketManager::formatReferenceRatio($refrates['deuterium']);
        $current_market_value_metal = $fleetResource[901] / $refrates['metal'];
        $current_market_value_crystal = $fleetResource[902] / $refrates['crystal'];
        $current_market_value_deuterium = $fleetResource[903] / $refrates['deuterium'];

        $current_market_value = $current_market_value_metal + $current_market_value_crystal + $current_market_value_deuterium;

        $sql = 'INSERT INTO %%LOG_FLEETS%% SET
            `id`                    = :fleetId,
            `group`                 = :fleetGroup,
            `universe`              = :universe,
            `mission`               = :fleetMission,
            `owner`                 = :fleetStartOwner,
            `owner_name`            = :fleetStartOwnerName,
            `target_owner`          = :fleetTargetOwner,
            `target_owner_name`     = :fleetTargetOwnerName,
            `ship_amount`           = :fleetShipCount,
            `ships`                 = :fleetData,
            `start_ships`           = :fleetDataStart,
            `start_time`            = :startTime,
            `start_time_formated`   = :startTimeFormated,
            `arrival_time`           = :arrivalTime,
            `arrival_time_formated`  = :arrivalTimeFormated,
            `stay_time`             = :stayTime,
            `stay_time_formated`    = :stayTimeFormated,
            `return_time`           = :endTime,
            `return_time_formated`  = :endTimeFormated,
            `start_id`              = :fleetStartPlanetID,
            `start_galaxy`          = :fleetStartPlanetGalaxy,
            `start_system`          = :fleetStartPlanetSystem,
            `start_planet`          = :fleetStartPlanetPlanet,
            `start_type`            = :fleetStartPlanetType,
            `end_id`                = :fleetTargetPlanetID,
            `end_galaxy`            = :fleetTargetPlanetGalaxy,
            `end_system`            = :fleetTargetPlanetSystem,
            `end_planet`            = :fleetTargetPlanetPlanet,
            `end_type`              = :fleetTargetPlanetType,
            `missile_target`        = :missileTarget,
            `fuel_cost`             = :fuelCost,
            `resource_metal`        = :fleetResource901,
            `resource_crystal`      = :fleetResource902,
            `resource_deuterium`    = :fleetResource903,
            `current_market_rate`   = :current_market_rate,
            `current_market_value_based_on_DSE` = :current_market_value,
            `cannotCancel`          = :cannotCancel;';

        $fleetStartOwnerName = $db->selectSingle("SELECT username from %%USERS%% WHERE id = :id", [':id' => $flight[':fleetStartOwner']], 'username');
        $fleetTargetOwnerName = $db->selectSingle("SELECT username from %%USERS%% WHERE id = :id", [':id' => $flight[':fleetTargetOwner']], 'username');

        $db->insert($sql, $flight + [
            ':fleetId' => $fleetId,
            ':fleetStartOwnerName' => $fleetStartOwnerName,
            ':fleetTargetOwnerName' => $fleetTargetOwnerName,
            ':startTimeFormated' => $this->db->formatDate($flight[':startTime']),
            ':arrivalTimeFormated' => $this->db->formatDate($flight[':arrivalTime']),
            ':stayTimeFormated' => $this->db->formatDate($flight[':stayTime']),
            ':endTimeFormated' => $this->db->formatDate($flight[':endTime']),
            ':current_market_rate' => $current_market_rate,
            ':current_market_value' => $current_market_value,
        ]);
    }
}
