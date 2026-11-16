<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Missions;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\Core\StoredData;
use App\Game\BattleEngine\BattleHandler;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\BuildFunctions;
use App\Game\Core\FleetFunctions;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;

/**
 * Mission "espionage" (6): probes spy on a planet or moon and deliver a spy report; the defender may shoot them down.
 */
class MissionCaseSpy extends MissionFunctions implements MissionInterface
{
    public function __construct($Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet   = $Fleet;
    }

    /**
     *
     * @param array $targetPlanet
     * @return array[]
     *
     * call AFTER stayfleets calculation, or calculate those yourself
     *
     */
    private function getPlanetFleet($targetPlanet)
    {
        $reslist = $this->state->reslist;
        $resource = $this->state->resource;
        $planetfleet = [];
        $resourceIDs = $reslist['fleet'];

        foreach ($resourceIDs as $shiptype) {
            $planetfleet[$shiptype] = $targetPlanet[$resource[$shiptype]];
        }

        return $planetfleet;
    }

    /**
     * Defense counts (all `reslist['defense']` ids) on the planet row, by element id.
     */
    private function getPlanetDefense($targetPlanet)
    {
        $reslist = $this->state->reslist;
        $resource = $this->state->resource;
        $planetdef = [];
        $resourceIDs = $reslist['defense'];

        foreach ($resourceIDs as $deftype) {
            $planetdef[$deftype] = $targetPlanet[$resource[$deftype]];
        }

        return $planetdef;
    }

    /**
     * Arrival of the probes. Turns around when the planet is gone or the owner is in vacation mode. Otherwise:
     * 1. Brings the target planet up to date (arrival time) and adds the ships of hold fleets stationed there.
     * 2. Information level: with spy tech levels s (sender) and t (target), both at least 1, the probes needed for
     *    fleets are n0 = +-((|s-t|*SPY_DIFFENCE_FACTOR)^2) (negative if the sender has the better tech); defense
     *    is visible with n0+1*SPY_VIEW_FACTOR probes, buildings n0+3*, research n0+5*. Resources are always shown.
     * 3. Counter-espionage chance (OGame rule): min(round(2^(t-s) * probes * ships_and_defenses_on_planet * 0.0025), 100)
     *    percent against a random roll 1..100; planets without ships or defense never shoot.
     * 4. Collects the report data (also ships in the repair dock queue, cargo needed to loot half of the planet,
     *    danger = summed attack, energy class) and sends it as JSON to the owner (spy report message); the
     *    target owner gets a "foreign fleet seen" notice every time.
     * 5. If the counter-espionage roll succeeded (chance >= roll) the probes are destroyed: their debris
     *    (fleet_debris_percentage of the probe cost) is added to the debris field (moon: parent planet) and the
     *    destroyed-ships stat is counted; otherwise the probes return home.
     */
    public function TargetEvent()
    {
        $pricelist = $this->state->pricelist;
        $resource = $this->state->resource;
        $db = $this->db;

        $sql = 'SELECT * FROM %%PLANETS%% WHERE `id` = :planetId FOR UPDATE;';
        $targetPlanet = $db->selectSingle($sql, [
            ':planetId' => $this->_fleet['end_id'],
        ]);

        // return fleet if target planet deleted
        if ($targetPlanet == false) {
            $this->setState(FLEET_RETURN);
            $this->SaveFleet();
            return;
        }

        // vacation mode of the target owner: hostile missions turn around
        if ($this->returnIfTargetInVacation($targetPlanet['id_owner'])) {
            return;
        }

        [$senderUser, $targetUser, $senderPlanet] = $this->loadParticipants();

        $LNG = $this->getLanguage($senderUser['lang']);

        $planetUpdater = new ResourceUpdate($this->db, $this->configs, $this->state);
        [$targetUser, $targetPlanet] = $planetUpdater->CalcResource(
            $targetUser,
            $targetPlanet,
            true,
            $this->_fleet['arrival_time']
        );

        $targetPlanet = $this->addHoldFleets($targetPlanet);

        $fleetAmount = $this->_fleet['ship_amount'];

        $senderSpyTech = max($senderUser['spy_tech'], 1);
        $targetSpyTech = max($targetUser['spy_tech'], 1);

        [$classIDs, $SpyFleet] = $this->visibleClasses($fleetAmount, $senderSpyTech, $targetSpyTech);

        // what is seen by the 'attacker' is already calculated above.
        // if target planet does not have fleet or def, it is safe to set $targetChance to -1
        // so probes are no longer destroyed by thin air or something

        $totalShipDefCount = 0;
        foreach (array_values($this->getPlanetFleet($targetPlanet)) as $ships) {
            $totalShipDefCount += $ships;
        }

        $targetChance = $this->getDetectionChance($senderSpyTech, $targetSpyTech, $fleetAmount, $totalShipDefCount);
        $spyChance = random_int(1, 100);

        $spyData = $this->collectSpyData($classIDs, $senderUser, $targetUser, $targetPlanet);
        $repair_order = $this->addRepairDockShips($spyData, $SpyFleet, $targetPlanet);

        $reportData = $this->buildReportData($senderUser, $targetUser, $targetPlanet, $spyData, $repair_order, $targetChance, $spyChance, $totalShipDefCount);

        $this->sendReport($reportData, $LNG);

        $this->notifyTargetOwner($senderUser, $targetUser, $senderPlanet, $targetPlanet);

        if ($totalShipDefCount > 0 && $targetChance >= $spyChance) {
            $this->destroyProbes($fleetAmount);
        } else {
            $this->setState(FLEET_RETURN);
            $this->SaveFleet();
        }
    }

    /**
     * Chance (percent, at most 100) that the target shoots the probes down. OGame rule
     * (https://ogame.fandom.com/wiki/Counterespionage): 2^(t-s) * probes * ships_and_defenses_on_planet * 0.0025,
     * rounded; t/s = spy tech of target/sender.
     */
    private function getDetectionChance(int|float|string $senderSpyTech, int|float|string $targetSpyTech, int|float|string $fleetAmount, int|float|string $totalShipDefCount): int|float
    {
        // Calculation from https://ogame.fandom.com/wiki/Counterespionage
        return min(round(pow(2, $targetSpyTech - $senderSpyTech) * $fleetAmount * $totalShipDefCount * 0.0025), 100);
    }

    /**
     * The content of the spy report (stored as JSON in the message): what the probes saw, the danger value of the
     * visible ships and defense, the cargo ships needed to loot half of the planet, the planet's energy class (to
     * spot players building gravitons), and both counter-espionage numbers.
     *
     * @param array<string, mixed> $senderUser fleet owner (users + settings)
     * @param array<string, mixed> $targetUser target owner
     * @param array<string, mixed> $targetPlanet planet row (resources as of the arrival)
     * @param array<int, array<int, int|float|string>> $spyData see collectSpyData()
     * @param array<int|string, int|float|string> $repair_order ships in the repair dock
     * @return array<string, mixed>
     */
    private function buildReportData(array|false $senderUser, array|false $targetUser, array $targetPlanet, array $spyData, array $repair_order, int|float $targetChance, int $spyChance, int|float $totalShipDefCount): array
    {
        [$smallCargoNeeded, $largeCargoNeeded] = $this->cargoNeeded($targetPlanet);

        // Scavengers Tool Box

        $stbSettings = $this->playerUtil()->player_stb_settings($senderUser);

        // determine if the spy report detects ships or deff
        $danger = $this->getDangerValue($spyData);
        $dangerClass = $danger > 0 ? "danger" : "nonedanger";

        // get the energy to indicate if someone is pushing to gravi
        $energy = $targetPlanet["energy"];
        if ($energy > 250000) {
            $energyClass = "lowRess";
        } elseif ($energy > 100000) {
            $energyClass = "midRess";
        } else {
            $energyClass = "";
        }

        $reportData = [
            'targetUserName'    => $targetUser['username'],
            'time'              => $this->_fleet['stay_time'],
            'stbEnabled'        => $stbSettings['stb_enabled'],
            'danger'            => $danger,
            'dangerClass'       => $dangerClass,
            'smallCargoNeeded'  => $smallCargoNeeded,
            'largeCargoNeeded'  => $largeCargoNeeded,
            'energy'            => $energy,
            'energyClass'       => $energyClass,
            'spyData'           => $spyData,
            'repair_order'      => $repair_order,
            'targetPlanet'      => [
                'name'          => $targetPlanet['name'],
                'planet_type'   => $targetPlanet['planet_type'],
                'galaxy'        => $targetPlanet['galaxy'],
                'system'        => $targetPlanet['system'],
                'planet'        => $targetPlanet['planet'],
            ],
            'targetChance'      => $targetChance,
            'spyChance'         => $spyChance,
            'totalShipDefCount' => $totalShipDefCount,
            'isBattleSim'       => ENABLE_SIMULATOR_LINK == true && $this->viewHelpers()->isModuleAvailable(MODULE_SIMULATOR),
        ];

        return $reportData;
    }

    /**
     * Cargo ships needed to carry the planet's resources (the capacity BattleHandler::calculateMinCapacity() gives for
     * metal, crystal and deuterium).
     *
     * @param array<string, mixed> $targetPlanet planet row
     * @return array{0: float, 1: float} small and large cargos needed
     */
    private function cargoNeeded(array $targetPlanet): array
    {
        $pricelist = $this->state->pricelist;
        $resource = $this->state->resource;
        $battleHandler = new BattleHandler(false, $this->db, $this->configs, $this->state);

        $capacityNeeded = $battleHandler->calculateMinCapacity([
            'metal' => getNumber($targetPlanet[$resource[RESOURCE_METAL]]),
            'crystal' => getNumber($targetPlanet[$resource[RESOURCE_CRYSTAL]]),
            'deuterium' => getNumber($targetPlanet[$resource[RESOURCE_DEUT]]),
        ]);
        $smallCargoNeeded = ceil($capacityNeeded / $pricelist[SHIP_SMALL_CARGO]['capacity']);
        $largeCargoNeeded = ceil($capacityNeeded / $pricelist[SHIP_LARGE_CARGO]['capacity']);

        return [$smallCargoNeeded, $largeCargoNeeded];
    }

    /**
     * Sends the spy report (JSON) to the fleet owner and sets the "first espionage" stat.
     *
     * @param array<string, mixed> $reportData report content
     */
    private function sendReport(array $reportData, \App\Game\Core\Language $LNG): void
    {
        $this->playerUtil()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_spy_qg'],
            MESSAGE_TYPE_SPY_REPORT,
            $LNG['sys_mess_spy_report'],
            json_encode($reportData),
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id']
        );

        $this->statsHandler->setTimestamp($this->_fleet['owner'], AdvancedStatsEnum::FIRST_ESPIONAGE, $this->_fleet['arrival_time']);
    }

    /**
     * Loads the rows the report needs.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} sender user (with settings),
     *         target user (with settings), name and type of the sender's start planet
     */
    private function loadParticipants(): array
    {
        $db = $this->db;
        $sql = 'SELECT * FROM %%USERS%% u 
            INNER JOIN %%USERS_SETTINGS%% us ON u.`id` = us.`user_id` 
            WHERE u.`id` = :userId;';
        $senderUser = $db->selectSingle($sql, [
            ':userId'   => $this->_fleet['owner'],
        ]);

        $targetUser = $db->selectSingle($sql, [
            ':userId'   => $this->_fleet['target_owner']
        ]);

        $sql = 'SELECT `name`, `planet_type` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $senderPlanet = $db->selectSingle($sql, [
            ':planetId' => $this->_fleet['start_id']
        ]);

        return [$senderUser, $targetUser, $senderPlanet];
    }

    /**
     * Adds the ships of the hold fleets that stand at the target at the arrival time to the planet's ship counts (the
     * probes see them too). Locks those fleet rows (FOR UPDATE).
     *
     * @param array<string, mixed> $targetPlanet planet row
     * @return array<string, mixed> the planet row with the stationed ships added (in memory only)
     */
    private function addHoldFleets(array $targetPlanet): array
    {
        $resource = $this->state->resource;
        $sql = 'SELECT * FROM %%FLEETS%%
            WHERE `end_id` = :planetId
            AND `mission` = :mission
            AND `arrival_time` <= :time
            AND `stay_time` >= :time FOR UPDATE;';

        $targetStayFleets = $this->db->select($sql, [
            ':planetId' => $this->_fleet['end_id'],
            ':time'     => $this->_fleet['arrival_time'],
            ':mission'  => MISSION_HOLD,
        ]);

        foreach ($targetStayFleets as $fleetRow) {
            $fleetData = $this->fleetFunctions()->unserialize($fleetRow['ships']);
            foreach ($fleetData as $shipId => $shipAmount) {
                $targetPlanet[$resource[$shipId]] += $shipAmount;
            }
        }

        return $targetPlanet;
    }

    /**
     * Information level of the report: with the spy techs s (sender) and t (target) the probes needed for fleets are
     * n0 = +-((|s-t|*SPY_DIFFENCE_FACTOR)^2) (negative if the sender has the better tech); defense is visible with
     * n0+1*SPY_VIEW_FACTOR probes, buildings n0+3*, research n0+5*. Resources are always shown.
     *
     * @return array{0: array<int, array<int, int>>, 1: bool} element ids per class (900 resources, 200 fleet, 400 defense,
     *         0 buildings, 100 research; only the visible classes), and whether the fleet is visible
     */
    private function visibleClasses(int|float|string $fleetAmount, int|float|string $senderSpyTech, int|float|string $targetSpyTech): array
    {
        $reslist = $this->state->reslist;
        $techDifference = abs($senderSpyTech - $targetSpyTech);
        $MinAmount = ($senderSpyTech > $targetSpyTech ? -1 : 1) * pow($techDifference * SPY_DIFFENCE_FACTOR, 2);
        $SpyFleet = $fleetAmount >= $MinAmount;
        $SpyDef = $fleetAmount >= $MinAmount + 1 * SPY_VIEW_FACTOR;
        $SpyBuild = $fleetAmount >= $MinAmount + 3 * SPY_VIEW_FACTOR;
        $SpyTechno = $fleetAmount >= $MinAmount + 5 * SPY_VIEW_FACTOR;

        $classIDs[900] = array_merge($reslist['resstype'][1], $reslist['resstype'][2]);

        if ($SpyFleet) {
            $classIDs[200] = $reslist['fleet'];
        }

        if ($SpyDef) {
            $classIDs[400] = array_merge($reslist['defense'], $reslist['missile']);
        }

        if ($SpyBuild) {
            $classIDs[0] = $reslist['build'];
        }

        if ($SpyTechno) {
            $classIDs[100] = $reslist['tech'];
        }

        return [$classIDs, $SpyFleet];
    }

    /**
     * The amounts of the visible element classes: from the target user row if the column lives there (research),
     * otherwise from the planet; the decoy counts as missile launchers. The sender's "only show what exists" setting
     * drops zero entries.
     *
     * @param array<int, array<int, int>> $classIDs see visibleClasses()
     * @return array<int, array<int, int|float|string>> class id => element id => amount
     */
    private function collectSpyData(array $classIDs, array|false $senderUser, array|false $targetUser, array $targetPlanet): array
    {
        $resource = $this->state->resource;
        $spyData = [];

        foreach ($classIDs as $classID => $elementIDs) {
            foreach ($elementIDs as $elementID) {
                if (!$this->buildFunctions()->isEnabled($elementID)) {
                    continue;
                }
                if (isset($targetUser[$resource[$elementID]])) {
                    if ($elementID == DECOY) {
                        $spyData[$classID][MISSILE_LAUNCHER] = $targetPlanet[$resource[$elementID]];
                    } else {
                        $spyData[$classID][$elementID] = $targetUser[$resource[$elementID]];
                    }
                } else {
                    if ($elementID == DECOY) {
                        $spyData[$classID][MISSILE_LAUNCHER] += $targetPlanet[$resource[$elementID]];
                    } else {
                        $spyData[$classID][$elementID] = $targetPlanet[$resource[$elementID]];
                    }
                }
            }

            if ($senderUser['spyMessagesMode'] == 1) {
                $spyData[$classID] = array_filter($spyData[$classID]);
            }
        }

        return $spyData;
    }

    /**
     * Shows the ships in the repair dock queue: adds them (with 0 if not on the planet) to the fleet block of the
     * report data. Wreck fields of moon fights are stored under the parent planet id.
     *
     * @param array<int, array<int, int|float|string>> $spyData report data, changed in place (class 200)
     * @return array<int|string, int|float|string> the decoded repair order (empty when not visible or nothing repairing)
     */
    private function addRepairDockShips(array &$spyData, bool $spyFleet, array $targetPlanet): array
    {
        $db = $this->db;
        $repair_order = [];
        if ($spyFleet && $this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK)) {
            // Wreck fields of moon fights are stored under the parent planet id
            $wreckPlanetID = $this->_fleet['end_id'];
            if ($targetPlanet['planet_type'] == 3) {
                $wreckPlanetID = $db->selectSingle(
                    "SELECT `id` FROM %%PLANETS%% WHERE `id_luna` = :moonId;",
                    [':moonId' => $this->_fleet['end_id']],
                    'id'
                ) ?: $wreckPlanetID;
            }
            $sql = "SELECT `repair_order` FROM %%PLANET_WRECKFIELD%%
                WHERE `planetID` = :planetID;";
            $repairing = $db->selectSingle($sql, [
                ':planetID' => $wreckPlanetID,
            ], 'repair_order');
            if (!empty($repairing)) {
                $repair_order = StoredData::decode($repairing);
                foreach ($repair_order as $elementID => $amount) {
                    if (!isset($spyData[200][$elementID])) {
                        $spyData[200][$elementID] = 0;
                    }
                }
            }
        }

        return $repair_order;
    }

    /**
     * Sends the "foreign fleet seen" notice (spy defence message) to the target owner, in his language: who (sender
     * planet, owner, coordinates) probed which of his planets. Sent on every probe arrival.
     */
    private function notifyTargetOwner(array|false $senderUser, array|false $targetUser, array|false $senderPlanet, array $targetPlanet): void
    {
        $LNG = $this->getLanguage($targetUser['lang']);

        $sourceLink = $LNG->format('sys_mess_spy_link',
            $senderPlanet['name'],
            $senderUser['username'],
            $this->_fleet['start_galaxy'],
            $this->_fleet['start_system'],
            $this->_fleet['start_planet'],
        );

        $targetLink = $LNG->format('sys_mess_spy_link',
            $targetPlanet['name'],
            $LNG['type_planet_short_' . $targetPlanet['planet_type']],
            $this->_fleet['end_galaxy'],
            $this->_fleet['end_system'],
            $this->_fleet['end_planet'],
        );

        $targetMessage = $LNG->format('sys_mess_spy_ennemyfleet_' . $senderPlanet['planet_type'],
            $sourceLink,
            $targetLink,
        );

        $this->playerUtil()->sendMessage(
            $this->_fleet['target_owner'],
            0,
            $LNG['sys_mess_spy_control'],
            MESSAGE_TYPE_SPY_DEFENCE,
            $LNG['sys_mess_spy_activity'],
            $targetMessage,
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id']
        );
    }

    /**
     * Counter-espionage succeeded: the probes are shot down. Their debris (fleet_debris_percentage of the probe cost)
     * goes to the debris field (a moon: its parent planet), the destroyed-ships stat is counted, the fleet is deleted.
     */
    private function destroyProbes(int|float|string $fleetAmount): void
    {
        $pricelist = $this->state->pricelist;
        $config = $this->configs->get($this->_fleet['universe']);
        $whereCol = $this->_fleet['end_type'] == 3 ? "id_luna" : "id";

        $sql = 'UPDATE %%PLANETS%% SET
			der_metal   = der_metal + :metal,
			der_crystal = der_crystal + :crystal,
            tf_active = :new_debris
			WHERE ' . $whereCol . ' = :planetId;';

        $this->db->update($sql, [
            ':metal'    => $fleetAmount * $pricelist[SHIP_PROBE]['cost'][RESOURCE_METAL] * $config->fleet_debris_percentage / 100,
            ':crystal'  => $fleetAmount * $pricelist[SHIP_PROBE]['cost'][RESOURCE_CRYSTAL] * $config->fleet_debris_percentage / 100,
            ':new_debris' => 1,
            ':planetId' => $this->_fleet['end_id'],
        ]);

        $this->statsHandler->updateDestroyedAdvancedStats($this->_fleet['target_owner'], $this->_fleet['owner'], SHIP_PROBE, $fleetAmount);

        $this->KillFleet();
    }

    /**
     * Returns the combined base attack values of ships and defences
     */
    public function getDangerValue($spyData)
    {
        $CombatCaps = $this->state->CombatCaps;
        $dangerValue = 0;

        // ships
        if (isset($spyData[200])) {
            foreach ($spyData[200] as $elementID => $amount) {
                $dangerValue += $CombatCaps[$elementID]['attack'] * $amount;
            }
        }

        // defence
        if (isset($spyData[400])) {
            foreach ($spyData[400] as $elementID => $amount) {
                if (
                    !($elementID == INTERCEPTOR_MISSILE) and
                    !($elementID == INTERPLANETARY_MISSILE)
                ) {
                    $dangerValue += $CombatCaps[$elementID]['attack'] * $amount;
                }
            }
        }

        return  $dangerValue;
    }

    /**
     * Returns the combined recycle values of ships and defences
     */
    public function getRecyleValue($spyData)
    {
        $pricelist = $this->state->pricelist;

        $config = $this->configs->get($this->_fleet['universe']);
        $fleetIntoDebris = $config->fleet_debris_percentage;
        $defIntoDebris = $config->def_debris_percentage;

        $recycleValue = 0;

        // ships
        if (isset($spyData[200]) && $fleetIntoDebris > 0) {
            foreach ($spyData[200] as $elementID => $amount) {
                $recycleValue += (($pricelist[$elementID]['cost'][RESOURCE_METAL] +
                    $pricelist[$elementID]['cost'][RESOURCE_CRYSTAL]) * $fleetIntoDebris / 100) * $amount;
            }
        }

        // defence
        if (isset($spyData[400]) && $defIntoDebris > 0) {
            foreach ($spyData[400] as $elementID => $amount) {
                $recycleValue += (($pricelist[$elementID]['cost'][RESOURCE_METAL] +
                    $pricelist[$elementID]['cost'][RESOURCE_CRYSTAL]) * $defIntoDebris / 100) * $amount;
            }
        }

        return  $recycleValue;
    }

    /**
     * Recyclers needed to carry the given debris amount, plus one as safety margin.
     */
    public function estimateRecyclers($recycleValue)
    {
        $pricelist = $this->state->pricelist;
        return ceil($recycleValue / $pricelist[SHIP_RECYCLER]["capacity"]) + 1;
    }

    /**
     * No hold phase.
     */
    public function EndStayEvent()
    {
        return;
    }

    /**
     * Probes are back: lands them at the start planet (no message; the report was sent on arrival).
     */
    public function ReturnEvent()
    {
        $this->RestoreFleet();
    }
}
