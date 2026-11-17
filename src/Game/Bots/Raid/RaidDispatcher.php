<?php

namespace App\Game\Bots\Raid;

use App\Game\Bots\BotActions;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Sends a bot's espionage, attack or transport (own planets, with cargo, any speed setting) fleet and recalls it, under the rules a player's fleet dispatch applies (FleetDispatch,
 * fleet step 3): own vacation mode, free fleet slots, existing target that is not own, ships on the planet, the mission offered
 * for that target and fleet (FleetFunctions::getFleetMissions), target in vacation, the bash rule for attacks (BashRules via
 * FleetFunctions::checkBash, probe-only fleets exempt), admin protection, noob / strong protection, deuterium for the flight.
 * Where a player would become an outlaw (attacking a "strong" player with the outlaw rule on) the bot refuses instead. Staff
 * accounts are refused even when the universe allows attacking staff.
 *
 * The caller holds the bot's rows locked in a transaction (BotActions::load()); the in-memory planet is kept in sync.
 */
class RaidDispatcher
{
    use GameUtilities;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly RaidPlanner $planner,
    ) {}

    /**
     * Sends `$fleet` from `$planet` to the target planet on a hostile mission (attack or spy).
     *
     * @param array<string, mixed> $planet start planet row of the bot (`BotActions::$planets`), updated in place
     * @param array<int, int> $fleet ship id => count
     * @param int $mission MISSION_ATTACK or MISSION_SPY
     * @param int $targetPlanetId id of the planet to hit
     * @param int $targetUserId owner the bot decided to hit (a changed owner refuses)
     * @param array<int, int|float> $cargo resources to carry (901 / 902 / 903 => amount), for a transport to an own planet
     * @param int $speedSetting 1-10, 10 = full speed (slow flights are how fleet saving is timed)
     * @return array{ok: bool, reason: string, id: int, arrival: int, duration: int, consumption: float}
     */
    public function send(BotActions $ctx, array &$planet, array $fleet, int $mission, int $targetPlanetId, int $targetUserId, array $cargo = [], int $speedSetting = 10): array
    {
        $cargo = [RESOURCE_METAL => floor((float) ($cargo[RESOURCE_METAL] ?? 0)), RESOURCE_CRYSTAL => floor((float) ($cargo[RESOURCE_CRYSTAL] ?? 0)), RESOURCE_DEUT => floor((float) ($cargo[RESOURCE_DEUT] ?? 0))];
        $refusal = $this->refusal($ctx, $planet, $fleet, $mission, $targetPlanetId, $targetUserId);
        if (is_string($refusal)) {
            return ['ok' => false, 'reason' => $refusal, 'id' => 0, 'arrival' => 0, 'duration' => 0, 'consumption' => 0.0];
        }
        [$targetPlanet] = $refusal;
        $e = $this->elements();

        $flight = $this->planner->flight($ctx, $planet, $fleet, (int) $targetPlanet['galaxy'], (int) $targetPlanet['system'], (int) $targetPlanet['planet'], max(1, min(10, $speedSetting)));
        $no = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'id' => 0, 'arrival' => 0, 'duration' => 0, 'consumption' => 0.0];
        if ((float) $planet[$e->column(RESOURCE_DEUT)] < $flight['consumption'] + $cargo[RESOURCE_DEUT]) {
            return $no('not enough deuterium');
        }
        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL] as $resourceId) {
            if ((float) $planet[$e->column($resourceId)] < $cargo[$resourceId]) {
                return $no('not enough ' . $e->column($resourceId));
            }
        }
        if (array_sum($cargo) > $this->fleetFunctions()->getFleetRoom($fleet) - $flight['consumption']) {
            return $no('cargo does not fit');
        }

        $arrival = (int) (floor($flight['duration']) + $this->state->runtime->timestamp);
        $id = $this->fleetFunctions()->sendFleet(
            $fleet,
            $mission,
            (int) $ctx->user['id'],
            (int) $planet['id'],
            (int) $planet['galaxy'],
            (int) $planet['system'],
            (int) $planet['planet'],
            (int) $planet['planet_type'],
            $targetUserId,
            $targetPlanetId,
            (int) $targetPlanet['galaxy'],
            (int) $targetPlanet['system'],
            (int) $targetPlanet['planet'],
            (int) $targetPlanet['planet_type'],
            $cargo,
            $arrival,
            $arrival,
            (int) floor($arrival + $flight['duration']),
            0,
            0,
            0,
            $flight['consumption'],
        );

        foreach ($fleet as $ship => $count) {
            $planet[$e->column($ship)] -= $count;
        }
        $planet[$e->column(RESOURCE_DEUT)] -= $flight['consumption'];
        foreach ($cargo as $resourceId => $amount) {
            $planet[$e->column($resourceId)] -= $amount;
        }

        return ['ok' => true, 'reason' => '', 'id' => $id, 'arrival' => $arrival, 'duration' => $flight['duration'], 'consumption' => $flight['consumption']];
    }

    /**
     * Recalls a fleet of the bot (the game's normal fleet return, as the fleet page does for a player).
     *
     * @return bool false when the game refuses the recall (fleet gone, already returning, not cancelable)
     */
    public function recall(BotActions $ctx, int $fleetId): bool
    {
        return $this->fleetFunctions()->sendFleetBack($ctx->user, $fleetId);
    }

    /**
     * All checks of a dispatch; the reason of the first failing one, or the loaded target rows when everything passes.
     *
     * @return string|array{0: array<string, mixed>, 1: array<string, mixed>} refusal text, or [target planet row, target user row]
     */
    private function refusal(BotActions $ctx, array $planet, array $fleet, int $mission, int $targetPlanetId, int $targetUserId): string|array
    {
        $e = $this->elements();
        $user = $ctx->user;
        if (IsVacationMode($user)) {
            return 'own vacation mode';
        }
        if ($ctx->freeFleetSlots() < 1) {
            return 'no free fleet slot';
        }
        foreach ($fleet as $ship => $count) {
            if ($count < 1 || $count > (int) $planet[$e->column($ship)]) {
                return 'ships not on the planet';
            }
        }

        $target = $this->db->selectSingle(
            'SELECT `id`, `id_owner`, `galaxy`, `system`, `planet`, `planet_type`, `der_metal`, `der_crystal`, `destruyed`, `ally_deposit`
            FROM %%PLANETS%% WHERE `id` = :id AND `universe` = :uni;',
            [':id' => $targetPlanetId, ':uni' => $this->universe()->current()],
        );
        if (empty($target) || !empty($target['destruyed']) || (int) $target['id_owner'] !== $targetUserId) {
            return 'target gone';
        }
        $own = (int) $target['id_owner'] === (int) $user['id'];
        if ($own && !in_array($mission, [MISSION_TRANSPORT, MISSION_STATION], true)) {
            return 'own planet';
        }
        $targetUser = $this->db->selectSingle(
            'SELECT u.*, s.total_points FROM %%USERS%% u LEFT JOIN %%STATPOINTS%% s ON s.id_owner = u.id AND s.stat_type = 1 WHERE u.id = :id;',
            [':id' => $targetUserId],
        );
        if (empty($targetUser)) {
            return 'target gone';
        }

        return $this->missionRefusal($ctx, $fleet, $mission, $target, $targetUser, $own) ?? [$target, $targetUser];
    }

    /**
     * The mission rules of FleetDispatch for a hostile mission: offered mission, vacation, bash, admin / staff, noob protection.
     *
     * @param array<int, int> $fleet ship id => count
     * @param array<string, mixed> $target target planet row
     * @param array<string, mixed> $targetUser target user row (with `total_points`)
     * @return string|null refusal text, null when the mission is allowed
     */
    private function missionRefusal(BotActions $ctx, array $fleet, int $mission, array $target, array $targetUser, bool $own = false): ?string
    {
        $user = $ctx->user;
        $info = ['galaxy' => $target['galaxy'], 'system' => $target['system'], 'planet' => $target['planet'], 'planettype' => (int) $target['planet_type'], 'IsAKS' => 0, 'Ship' => $fleet];
        $available = $this->fleetFunctions()->getFleetMissions($user, $info, $target);
        if (!in_array($mission, $available['MissionSelector'], true)) {
            return 'mission not available for this target';
        }
        if ($own) {
            return null; // own planets: no protection or bash rule applies
        }
        if (IsVacationMode($targetUser)) {
            return 'target in vacation mode';
        }
        if ((int) $targetUser['authlevel'] > 0 || ($this->configs->get()->adm_attack == 1 && $targetUser['authattack'] > $user['authlevel'])) {
            return 'target is protected staff';
        }
        if ($mission === MISSION_ATTACK && (count($fleet) !== 1 || !array_key_exists(SHIP_PROBE, $fleet)) && $this->bash((int) $target['id'], $user)) {
            return 'bash limit';
        }

        $noob = $this->playerUtil()->checkNoobProtectionFor($this->models()->user($user), $this->models()->user($targetUser));
        if ($noob['NoobPlayer']) {
            return 'target under noob protection';
        }

        return $noob['StrongPlayer'] ? 'target is protected from small players (strong)' : null;
    }

    /**
     * BashRules::checkBash() for the bot (the rule reads the acting user from the game state, set for the call).
     *
     * @param array<string, mixed> $user the bot's user row
     */
    private function bash(int $planetId, array $user): bool
    {
        $previous = $this->state->USER;
        $this->state->USER = $user;
        try {
            return $this->fleetFunctions()->checkBash($planetId, 0);
        } finally {
            $this->state->USER = $previous;
        }
    }
}
