<?php

namespace App\Game\Bots;

use App\Game\Core\Config;
use App\Game\Core\StoredData;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\Singleton;

/**
 * Holds the game state of one bot (user + planets, locked inside the caller's
 * transaction) and executes actions with exactly the rules a human player has
 * (same price/requirement/queue/field checks as the building, research and
 * shipyard pages).
 */
class BotActions
{
    use GameUtilities;

    /** @var array<string,mixed> */
    public array $user = [];
    /** @var array<int,array<string,mixed>> planets (planet_type 1) keyed by id */
    public array $planets = [];
    public Config $config;
    public ResourceUpdate $eco;
    public int $universe;

    public function __construct(
        int $universe,
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly BotPersona $persona,
    ) {
        $this->universe = $universe;
        $this->config = $configs->get($universe);
        $this->eco = $this->resourceUpdate();
    }

    /**
     * Locks and loads user + planets (user first, then planets: same order as
     * the game pages) and brings resources/queues up to date. Must run inside
     * a transaction.
     */
    public function load(int $userId): bool
    {
        $db = $this->db;

        $user = $db->selectSingle(
            'SELECT u.*, us.*, s.total_points
            FROM %%USERS%% u
            INNER JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id
            LEFT JOIN %%STATPOINTS%% s ON s.id_owner = u.id AND s.stat_type = 1
            WHERE u.id = :id FOR UPDATE;',
            [':id' => $userId],
        );
        if (empty($user)) {
            return false;
        }

        $planets = $db->select(
            'SELECT * FROM %%PLANETS%% WHERE id_owner = :id AND destruyed = 0 AND planet_type = 1 ORDER BY id FOR UPDATE;',
            [':id' => $userId],
        );

        $this->planets = [];
        foreach ($planets as $planet) {
            [$user, $planet] = $this->eco->CalcResource($user, $planet, true);
            $planet[$this->res()[RESEARCH_LABORATORY] . '_inter'] = $this->eco->getNetworkLevel($user, $planet);
            $this->planets[(int) $planet['id']] = $planet;
        }
        $this->user = $user;

        return true;
    }

    /** "Name [g:s:p]" label of a planet for log lines. */
    public function planetLabel(array $planet): string
    {
        return $planet['name'] . ' [' . $planet['galaxy'] . ':' . $planet['system'] . ':' . $planet['planet'] . ']';
    }

    /** Element id => column name map (`$resource` from the game data). */
    public function res(): array
    {
        return $this->state->resource;
    }

    /** True when no building is under construction on the planet. */
    public function isBuildingQueueEmpty(array $planet): bool
    {
        return empty($planet['b_building_id']) && empty($planet['b_building']);
    }

    /** True when the user has no research running or queued. */
    public function isResearchQueueEmpty(): bool
    {
        return empty($this->user['b_tech_queue']) && empty($this->user['b_tech']);
    }

    /** true while a lab or university is being built on any planet (research blocked). */
    public function labInConstruction(): bool
    {
        foreach ($this->planets as $planet) {
            if (empty($planet['b_building_id'])) {
                continue;
            }
            $queue = StoredData::decode($planet['b_building_id']);
            if (!is_array($queue)) {
                continue;
            }
            foreach ($queue as $item) {
                if ($item[0] == university || $item[0] == RESEARCH_LABORATORY) {
                    return true;
                }
            }
        }

        return false;
    }

    /** true while shipyard or nanite factory is being built on the planet. */
    public function shipyardInConstruction(array $planet): bool
    {
        if (empty($planet['b_building_id'])) {
            return false;
        }
        $queue = StoredData::decode($planet['b_building_id']);
        if (!is_array($queue)) {
            return false;
        }
        foreach ($queue as $item) {
            if ($item[0] == SHIPYARD || $item[0] == NANITE_FACTORY) {
                return true;
            }
        }

        return false;
    }

    /** Number of entries in the planet's shipyard queue. */
    public function hangarCount(array $planet): int
    {
        $queue = !empty($planet['b_hangar_id']) ? StoredData::decode($planet['b_hangar_id']) : [];

        return is_array($queue) ? count($queue) : 0;
    }

    /** Can a building be started on this planet right now (queue empty, fields)? */
    public function canStartBuilding(array $planet): bool
    {
        return $this->isBuildingQueueEmpty($planet)
            && $this->buildFunctions()->canQueueBuildingOnFields($planet, [], true);
    }

    /** Can the element be bought as a building right now, ignoring resources? Returns level to build or null. */
    public function buildableLevel(array $planet, int $element): ?int
    {
        $resource = $this->res();
        $reslist = $this->state->reslist;
        $pricelist = $this->state->pricelist;

        if (
            !in_array($element, $reslist['allow'][$planet['planet_type']])
            || !$this->buildFunctions()->isEnabled($element)
            || !$this->buildFunctions()->isTechnologieAccessible($this->user, $planet, $element)
            || ($element == RESEARCH_LABORATORY && $this->user['b_tech_planet'] != 0)
            || (($element == NANITE_FACTORY || $element == SHIPYARD) && !empty($planet['b_hangar_id']))
        ) {
            return null;
        }

        $level = (int) $planet[$resource[$element]] + 1;
        if ($pricelist[$element]['max'] < $level) {
            return null;
        }

        return $level;
    }

    /**
     * Starts the next level of a building like the buildings page: needs an empty building queue, free fields, an allowed
     * and accessible element within its max level, and enough resources. Deducts the price, writes the queue with end
     * time and saves it. Returns a log text, or null when any rule rejects it (nothing changed).
     */
    public function build(int $planetId, int $element): ?string
    {
        $resource = $this->res();
        $pricelist = $this->state->pricelist;
        $planet = &$this->planets[$planetId];

        if (!$this->canStartBuilding($planet)) {
            return null;
        }
        $level = $this->buildableLevel($planet, $element);
        if ($level === null) {
            return null;
        }

        $cost = $this->buildFunctions()->getElementPrice($this->user, $planet, $element, false, $level);
        if (!$this->buildFunctions()->isElementBuyable($this->user, $planet, $element, $cost)) {
            return null;
        }

        $this->eco->removeResources($planet['id'], $cost, $planet);

        $time = $this->buildFunctions()->getBuildingTime($this->user, $planet, $element, $cost, false);
        $end = $this->state->runtime->timestamp + $time;
        if ($planet['watch_id'] == $element) {
            $planet['watch_id'] = 0;
        }
        $planet['b_building_id'] = StoredData::encode([[$element, $level, $time, $end, 'build', $cost]]);
        $planet['b_building'] = $end;
        $this->eco->saveBuilingQueue($planet);

        return $resource[$element] . ' ' . $level . ' on ' . $this->planetLabel($planet);
    }

    /** Next research level of `$element` if it may be researched from this planet (listed, enabled, requirements met, below max), else null. Resources are not checked. */
    public function researchLevel(array $planet, int $element): ?int
    {
        $resource = $this->res();
        $reslist = $this->state->reslist;
        $pricelist = $this->state->pricelist;

        if (
            !in_array($element, $reslist['tech'])
            || !$this->buildFunctions()->isEnabled($element)
            || !$this->buildFunctions()->isTechnologieAccessible($this->user, $planet, $element)
        ) {
            return null;
        }
        $level = (int) $this->user[$resource[$element]] + 1;
        if ($pricelist[$element]['max'] < $level) {
            return null;
        }

        return $level;
    }

    /**
     * Starts the next level of a technology like the research page: research queue empty, no lab or university under
     * construction (anywhere), requirements and resources met. Deducts the price on `$planetId`, stores the single-entry research
     * queue and clears the planet's "watch" marker for that element. Returns a log text, or null when rejected.
     */
    public function research(int $planetId, int $element): ?string
    {
        $resource = $this->res();
        $planet = &$this->planets[$planetId];

        if (!$this->isResearchQueueEmpty() || $this->labInConstruction()) {
            return null;
        }
        $level = $this->researchLevel($planet, $element);
        if ($level === null) {
            return null;
        }

        $cost = $this->buildFunctions()->getElementPrice($this->user, $planet, $element, false, $level);
        if (!$this->buildFunctions()->isElementBuyable($this->user, $planet, $element, $cost)) {
            return null;
        }

        $this->eco->removeResources($planet['id'], $cost, $planet);

        $time = $this->buildFunctions()->getBuildingTime($this->user, $planet, $element, $cost);
        $end = $this->state->runtime->timestamp + $time;
        if ($planet['watch_id'] == $element) {
            $planet['watch_id'] = 0;
            $this->db->update(
                'UPDATE %%PLANETS%% SET watch_id = 0 WHERE id = :id;',
                [':id' => $planet['id']],
            );
        }

        $this->user['b_tech_queue'] = StoredData::encode([[$element, $level, $time, $end, $planet['id'], $cost]]);
        $this->user['b_tech'] = $end;
        $this->user['b_tech_id'] = $element;
        $this->user['b_tech_planet'] = $planet['id'];
        $this->eco->saveTechQueue($this->user);

        return $resource[$element] . ' ' . $level . ' on ' . $this->planetLabel($planet);
    }

    /** Can ships/defense be queued on this planet at all (shipyard present, not blocked, queue not full)? */
    public function canQueueUnits(array $planet): bool
    {
        $resource = $this->res();
        if ($planet[$resource[SHIPYARD]] < 1 || $this->shipyardInConstruction($planet)) {
            return false;
        }
        $max = (int) $this->config->max_elements_ships;

        return $max == 0 || $this->hangarCount($planet) < $max;
    }

    /** Whether the ship or defense `$element` may be built on the planet: enabled, requirements met, and for one-per-planet units (e.g. shields) not yet built or queued. */
    public function unitsAllowed(array $planet, int $element): bool
    {
        $reslist = $this->state->reslist;
        $resource = $this->res();

        if (
            !in_array($element, array_merge($reslist['fleet'], $reslist['defense']))
            || !$this->buildFunctions()->isEnabled($element)
            || !$this->buildFunctions()->isTechnologieAccessible($this->user, $planet, $element)
        ) {
            return false;
        }
        if (in_array($element, $reslist['one'])) {
            if ($planet[$resource[$element]] != 0) {
                return false;
            }
            $queue = !empty($planet['b_hangar_id']) ? StoredData::decode($planet['b_hangar_id']) : [];
            foreach ((array) $queue as $item) {
                if ($item[0] == $element) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Queues `$count` ships or defense units like the shipyard page. The count is capped by `max_fleet_per_build`, by what the planet
     * can pay and (one-per-planet units) to 1. Deducts the price, appends to the hangar queue, saves it and checks
     * shipyard achievements. Returns a log text, or null when nothing could be queued.
     */
    public function units(int $planetId, int $element, int $count): ?string
    {
        $resource = $this->res();
        $reslist = $this->state->reslist;
        $planet = &$this->planets[$planetId];

        if (!$this->canQueueUnits($planet) || !$this->unitsAllowed($planet, $element)) {
            return null;
        }

        $count = max(0, min($count, (int) $this->config->max_fleet_per_build));
        $count = min($count, (int) $this->buildFunctions()->getMaxConstructibleElements($this->user, $planet, $element));
        if (in_array($element, $reslist['one'])) {
            $count = min($count, 1);
        }
        if ($count < 1) {
            return null;
        }

        $cost = $this->buildFunctions()->getElementPrice($this->user, $planet, $element, false, $count);

        $queue = !empty($planet['b_hangar_id']) ? StoredData::decode($planet['b_hangar_id']) : [];
        if (!is_array($queue)) {
            $queue = [];
        }
        $queue[] = [$element, $count];
        $planet['b_hangar_id'] = StoredData::encode($queue);

        $this->miscAchievements()->checkShipyardAchievements($this->user['id'], $count, $element);
        $this->eco->removeResources($planet['id'], $cost, $planet);
        $this->eco->saveShipyardQueue($planet);

        return $count . ' x ' . $resource[$element] . ' on ' . $this->planetLabel($planet);
    }

    // ------------------------------------------------------------------
    // fleet actions
    // ------------------------------------------------------------------

    /** Fleet slots (computer technology and bonuses) minus fleets already flying. */
    private function freeFleetSlots(): int
    {
        return $this->fleetFunctions()->getMaxFleetSlots($this->user) - $this->fleetFunctions()->getCurrentFleets($this->user['id']);
    }

    /**
     * Sends a fleet in the same way FleetDispatch (fleet step 3) does for a mission without cargo, at full speed (speed setting 10 =
     * 100 %). Returns null without changes when the mission is not available for the target, the ships are not on
     * the planet, the stay time is not offered, or the planet lacks deuterium (id 903) for the trip. Otherwise sends it
     * through FleetFunctions::sendFleet() (DB write), keeps the in-memory planet in sync and returns the fleet id.
     *
     * @param array<int, int> $fleetArray ship id => count
     * @param int $stayHours expedition hold time in hours (0 = none)
     */
    private function dispatch(array &$planet, array $fleetArray, int $mission, int $galaxy, int $system, int $pos, int $stayHours = 0): ?int
    {
        $resource = $this->res();

        $targetPlanetData = ['id' => 0, 'id_owner' => 0];
        $misInfo = [
            'galaxy' => $galaxy,
            'system' => $system,
            'planet' => $pos,
            'planettype' => 1,
            'IsAKS' => 0,
            'Ship' => $fleetArray,
        ];
        $available = $this->fleetFunctions()->getFleetMissions($this->user, $misInfo, $targetPlanetData);
        if (!in_array($mission, $available['MissionSelector'])) {
            return null;
        }
        if ($stayHours > 0 && !isset($available['StayBlock'][$stayHours])) {
            return null;
        }

        foreach ($fleetArray as $ship => $count) {
            if ($count < 1 || $count > $planet[$resource[$ship]]) {
                return null;
            }
        }

        $distance = $this->fleetFunctions()->getTargetDistance(
            [$planet['galaxy'], $planet['system'], $planet['planet']],
            [$galaxy, $system, $pos],
        );
        $maxSpeed = $this->fleetFunctions()->getFleetMaxSpeed($fleetArray, $this->user);
        $speedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $duration = $this->fleetFunctions()->getMissionDuration(10, $maxSpeed, $distance, $speedFactor, $this->user);
        $consumption = $this->fleetFunctions()->getFleetConsumption($fleetArray, $duration, $distance, $this->user, $speedFactor);
        if ($planet[$resource[903]] < $consumption) {
            return null;
        }

        $stay = $stayHours > 0 ? (int) round($available['StayBlock'][$stayHours] * 3600) : 0;
        $start = (int) (floor($duration) + $this->state->runtime->timestamp);
        $stayTime = (int) floor($start + $stay);
        $end = (int) floor($stayTime + $duration);

        $id = $this->fleetFunctions()->sendFleet(
            $fleetArray,
            $mission,
            (int) $this->user['id'],
            (int) $planet['id'],
            (int) $planet['galaxy'],
            (int) $planet['system'],
            (int) $planet['planet'],
            (int) $planet['planet_type'],
            0,
            0,
            $galaxy,
            $system,
            $pos,
            1,
            [901 => 0, 902 => 0, 903 => 0],
            $start,
            $stayTime,
            $end,
            0,
            0,
            0,
            (float) $consumption,
        );

        // keep the in-memory planet in sync with what sendFleet deducted
        foreach ($fleetArray as $ship => $count) {
            $planet[$resource[$ship]] -= $count;
        }
        $planet[$resource[903]] -= $consumption;

        return $id;
    }

    /** Expedition module on in the universe, a free fleet slot and the expedition limit not reached. */
    public function canSendExpedition(): bool
    {
        return $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_EXPEDITION, $this->universe)
            && $this->freeFleetSlots() > 0
            && $this->fleetFunctions()->getCurrentFleets($this->user['id'], MISSION_EXPEDITION, true) < $this->fleetFunctions()->getExpeditionLimit($this->user);
    }

    /**
     * Sends an expedition to the expedition position (max_planets + 1) of the system of a random own planet that has
     * cargo ships: 40-100 % of its small and large cargos and, with 50 % chance, one probe; 1 hour hold time. Returns
     * a log text, or null when it cannot be sent.
     */
    public function expedition(): ?string
    {
        $resource = $this->res();
        if (!$this->canSendExpedition()) {
            return null;
        }

        $targetPos = (int) $this->config->max_planets + 1;
        $ids = [];
        foreach ($this->planets as $id => $planet) {
            $cargo = (int) $planet[$resource[SHIP_SMALL_CARGO]] + (int) $planet[$resource[SHIP_LARGE_CARGO]];
            if ($cargo >= 1) {
                $ids[] = $id;
            }
        }
        if (empty($ids)) {
            return null;
        }
        shuffle($ids);
        $planet = &$this->planets[$ids[0]];

        $fleet = [];
        $small = (int) $planet[$resource[SHIP_SMALL_CARGO]];
        $large = (int) $planet[$resource[SHIP_LARGE_CARGO]];
        if ($small > 0) {
            $fleet[SHIP_SMALL_CARGO] = max(1, (int) floor($small * (random_int(40, 100) / 100)));
        }
        if ($large > 0) {
            $fleet[SHIP_LARGE_CARGO] = max(1, (int) floor($large * (random_int(40, 100) / 100)));
        }
        if ($planet[$resource[SHIP_PROBE]] >= 1 && random_int(0, 1)) {
            $fleet[SHIP_PROBE] = 1;
        }

        $id = $this->dispatch($planet, $fleet, MISSION_EXPEDITION, (int) $planet['galaxy'], (int) $planet['system'], $targetPos, 1);
        if ($id === null) {
            return null;
        }

        $parts = [];
        foreach ($fleet as $ship => $count) {
            $parts[] = $count . ' ' . $resource[$ship];
        }

        return implode(', ', $parts) . ' from ' . $this->planetLabel($planet) . ' to [' . $planet['galaxy'] . ':' . $planet['system'] . ':' . $targetPos . ']';
    }

    /** Colony module on, a free fleet slot, below the maximum planet count and no colonization fleet already flying. */
    public function canColonize(): bool
    {
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_MISSION_COLONY, $this->universe) || $this->freeFleetSlots() < 1) {
            return false;
        }

        return $this->playerUtil()->currentPlanetCount($this->user) < $this->playerUtil()->maxPlanetCount($this->user)
            && $this->fleetFunctions()->getCurrentFleets($this->user['id'], MISSION_COLONISATION, true) == 0;
    }

    /**
     * Sends a colony ship from the first planet that has one to a random free position near it: up to 40 tries, system
     * within 3 (first 20 tries) or 10 systems, position 2 to max_planets - 2, allowed for the user and not already
     * targeted by a colonization fleet. Returns a log text, or null when no ship or no target was found.
     */
    public function colonize(): ?string
    {
        $resource = $this->res();
        if (!$this->canColonize()) {
            return null;
        }

        $origin = null;
        foreach ($this->planets as $id => $planet) {
            if ($planet[$resource[SHIP_COLOSHIP]] >= 1) {
                $origin = $id;
                break;
            }
        }
        if ($origin === null) {
            return null;
        }
        $planet = &$this->planets[$origin];

        $maxSystem = (int) $this->config->max_system;
        $maxPlanets = (int) $this->config->max_planets;
        $target = null;
        for ($try = 0; $try < 40; $try++) {
            $spread = $try < 20 ? 3 : 10;
            $system = (int) $planet['system'] + random_int(-$spread, $spread);
            $pos = random_int(2, max(2, $maxPlanets - 2));
            if ($system < 1 || $system > $maxSystem) {
                continue;
            }
            if (
                $this->playerUtil()->checkPosition($this->universe, (int) $planet['galaxy'], $system, $pos)
                && $this->playerUtil()->isPositionFree($this->universe, (int) $planet['galaxy'], $system, $pos)
                && $this->playerUtil()->allowPlanetPosition($pos, $this->user)
                && !$this->positionTargeted((int) $planet['galaxy'], $system, $pos)
            ) {
                $target = [(int) $planet['galaxy'], $system, $pos];
                break;
            }
        }
        if ($target === null) {
            return null;
        }

        $id = $this->dispatch($planet, [SHIP_COLOSHIP => 1], MISSION_COLONISATION, $target[0], $target[1], $target[2]);
        if ($id === null) {
            return null;
        }

        return 'colony ship from ' . $this->planetLabel($planet) . ' to [' . implode(':', $target) . ']';
    }

    /** Whether a colonization fleet is already heading for these coordinates. */
    private function positionTargeted(int $galaxy, int $system, int $pos): bool
    {
        return (int) $this->db->selectSingle(
            'SELECT COUNT(*) as count FROM %%FLEETS%%
            WHERE universe = :universe AND mission = :mission AND end_galaxy = :g AND end_system = :s AND end_planet = :p;',
            [':universe' => $this->universe, ':mission' => MISSION_COLONISATION, ':g' => $galaxy, ':s' => $system, ':p' => $pos],
            'count',
        ) > 0;
    }

    /**
     * Colonies are created when the colony ship arrives (fleet handler), with the
     * language's default colony name. Give such planets a persona based name.
     *
     * @return int number of planets renamed (the home planet is never renamed)
     */
    public function renameDefaultColonies(array $botRow, array $persona): int
    {
        $defaults = $this->defaultColonyNames();
        $renamed = 0;
        foreach ($this->planets as $id => $planet) {
            if ($id == $this->user['id_planet'] || !in_array($planet['name'], $defaults, true)) {
                continue;
            }
            $name = $this->persona->planetName($botRow, $persona, false);
            $this->persona->renamePlanet((int) $id, $name);
            $renamed++;
        }

        return $renamed;
    }

    /** @return string[] the default colony names (translation key `fcp_colony`) of all languages, read once from translations/*.yaml */
    private function defaultColonyNames(): array
    {
        static $names = null;
        if ($names === null) {
            $names = [];
            foreach (glob($this->state->runtime->rootPath . 'translations/*.yaml') ?: [] as $file) {
                $content = file_get_contents($file);
                if ($content !== false && preg_match('/^fcp_colony:\s*(.+)$/m', $content, $m)) {
                    $names[] = trim($m[1], " \t\"'");
                }
            }
            $names = array_values(array_unique($names));
        }

        return $names;
    }
}
