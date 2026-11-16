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
use App\Game\BattleEngine\BattleHandler;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\BuildFunctions;
use App\Game\Core\FleetFunctions;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;

/**
 * Mission "pirate base espionage" (21): probes spy on an NPC pirate base and deliver a spy report like
 * MissionCaseSpy, with the base's resources and ships (no buildings/defense; the research shown is the
 * sender's own weapon/shield/armor tech minus 3). The base counts as having spy tech sender - 1 (min 1).
 */
class MissionCasePiratebaseSpy extends MissionFunctions implements MissionInterface
{
    public function __construct($Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet   = $Fleet;
    }

    /**
     * Arrival: loads the base (probes return with a "base gone" message if it vanished), derives the visible
     * information classes (getClassIDs), builds and sends the spy report and rolls the detection chance
     * (getDetectionChance against a 1-100 roll): detected probes are destroyed and their debris goes to the base's
     * debris field; others return. Unlike planets there is no notice to a defender.
     */
    public function TargetEvent()
    {
        $pricelist = $this->state->pricelist;
        $resource = $this->state->resource;
        $db = $this->db;
        $config = $this->configs->get($this->_fleet['universe']);

        $sql = "SELECT * FROM %%PIRATE_BASES%% WHERE `id` = :baseId FOR UPDATE;";
        $targetBase = $db->selectSingle($sql, [
            ':baseId' => $this->_fleet['end_id'],
        ]);

        // return fleet if the base is gone (removed or refreshed since launch)
        if ($targetBase == false) {
            $this->sendPiratebaseGoneMessage();
            $this->setState(FLEET_RETURN);
            $this->SaveFleet();
            return;
        }

        $sql = "SELECT * FROM %%USERS%% u 
            INNER JOIN %%USERS_SETTINGS%% us ON u.`id` = us.`user_id` 
            WHERE u.`id` = :userId;";
        $senderUser = $db->selectSingle($sql, [
            ':userId'   => $this->_fleet['owner'],
        ]);
        $LNG = $this->getLanguage($senderUser['lang']);

        $fleetAmount = $this->_fleet['ship_amount'];
        $senderSpyTech = max($senderUser['spy_tech'], 1);
        $targetSpyTech = max($senderUser['spy_tech'] - 1, 1);
        $classIDs = $this->getClassIDs($fleetAmount, $senderSpyTech, $targetSpyTech);

        $targetFleet = $this->fleetFunctions()->unserialize($targetBase['ships']);

        $totalShipDefCount = 0;
        $spyData = [];
        foreach ($classIDs as $classID => $elementIDs) {
            if ($classID == 900) {
                foreach ($elementIDs as $elementID) {
                    if (isset($targetBase[$resource[$elementID]])) {
                        $spyData[$classID][$elementID] = $targetBase[$resource[$elementID]];
                    } else {
                        $spyData[$classID][$elementID] = 0;
                    }
                }
            } elseif ($classID == 200) {
                foreach ($elementIDs as $elementID) {
                    if (!$this->buildFunctions()->isEnabled($elementID)) {
                        continue;
                    }
                    if (isset($targetFleet[$elementID])) {
                        $spyData[$classID][$elementID] = $targetFleet[$elementID];
                        $totalShipDefCount += $targetFleet[$elementID];
                    } else {
                        $spyData[$classID][$elementID] = 0;
                    }
                }
            } elseif ($classID == 100) {
                $spyData[$classID][MILITARY_TECH] = max($senderUser[$resource[MILITARY_TECH]] - 3, 0);
                $spyData[$classID][SHIELD_TECH] = max($senderUser[$resource[SHIELD_TECH]] - 3, 0);
                $spyData[$classID][DEFENCE_TECH] = max($senderUser[$resource[DEFENCE_TECH]] - 3, 0);
            }

            if ($senderUser['spyMessagesMode'] == 1 && isset($spyData[$classID])) {
                $spyData[$classID] = array_filter($spyData[$classID]);
            }
        }

        $battleHandler = new BattleHandler(false, $this->db, $this->configs, $this->state);

        $targetChance = $this->getDetectionChance($senderSpyTech, $targetSpyTech, $fleetAmount, $totalShipDefCount);
        $spyChance = random_int(1, 100);

        $sumPlanetRessources = 2 * ($targetBase[$resource[RESOURCE_METAL]] + $targetBase[$resource[RESOURCE_CRYSTAL]] + $targetBase[$resource[RESOURCE_DEUT]]);
        $simulated = [];
        $simulated['fleetDetail']['resource_metal'] = 0;
        $simulated['fleetDetail']['resource_crystal'] = 0;
        $simulated['fleetDetail']['resource_deuterium'] = 0;
        $simulated['unit'] = [
            '203' => ceil($sumPlanetRessources / $pricelist[SHIP_LARGE_CARGO]['capacity']),
        ];
        $fleetSimulate[] = $simulated;

        $capacityNeeded = $battleHandler->calculateMinCapacity([
            'metal' => getNumber($targetBase[$resource[RESOURCE_METAL]]),
            'crystal' => getNumber($targetBase[$resource[RESOURCE_CRYSTAL]]),
            'deuterium' => getNumber($targetBase[$resource[RESOURCE_DEUT]]),
        ]);
        $smallCargoNeeded = ceil($capacityNeeded / $pricelist[SHIP_SMALL_CARGO]['capacity']);
        $largeCargoNeeded = ceil($capacityNeeded / $pricelist[SHIP_LARGE_CARGO]['capacity']);

        // Scavengers Tool Box
        $stbSettings = $this->playerUtil()->player_stb_settings($senderUser);

        // determine if the spy report detects ships or deff
        $danger = $this->getDangerValue($spyData);
        $dangerClass = $danger > 0 ? "danger" : "nonedanger";

        // get the energy to indicate if someone is pushing to gravi
        $energy = 0;
        $energyClass = "";

        $reportData = [
            'targetUserName'    => $LNG['type_planet_4'],
            'time'              => $this->_fleet['stay_time'],
            'stbEnabled'        => $stbSettings['stb_enabled'],
            'danger'            => $danger,
            'dangerClass'       => $dangerClass,
            'smallCargoNeeded'  => $smallCargoNeeded,
            'largeCargoNeeded'  => $largeCargoNeeded,
            'energy'            => $energy,
            'energyClass'       => $energyClass,
            'spyData'           => $spyData,
            'repair_order'      => [],
            'targetPlanet'      => [
                'name'          => $LNG['type_planet_4'],
                'planet_type'   => $this->_fleet['end_type'],
                'galaxy'        => $this->_fleet['end_galaxy'],
                'system'        => $this->_fleet['end_system'],
                'planet'        => $this->_fleet['end_planet'],
            ],
            'targetChance'      => $targetChance,
            'spyChance'         => $spyChance,
            'totalShipDefCount' => $totalShipDefCount,
            'isBattleSim'       => ENABLE_SIMULATOR_LINK == true && $this->viewHelpers()->isModuleAvailable(MODULE_SIMULATOR),
        ];

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

        if ($totalShipDefCount > 0 && $targetChance >= $spyChance) {

            $sql = 'UPDATE %%PIRATE_BASES%% SET
                `der_metal`   = `der_metal` + :metal,
                `der_crystal` = `der_crystal` + :crystal
                WHERE `id` = :baseId;';

            $db->update($sql, [
                ':metal'    => $fleetAmount * $pricelist[SHIP_PROBE]['cost'][RESOURCE_METAL] * $config->fleet_debris_percentage / 100,
                ':crystal'  => $fleetAmount * $pricelist[SHIP_PROBE]['cost'][RESOURCE_CRYSTAL] * $config->fleet_debris_percentage / 100,
                ':baseId' => $this->_fleet['end_id'],
            ]);

            $this->statsHandler->updateDestroyedAdvancedStats(0, $this->_fleet['owner'], SHIP_PROBE, $fleetAmount);

            $this->KillFleet();
        } else {
            $this->setState(FLEET_RETURN);
            $this->SaveFleet();
        }
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
     * Probes are back: lands them at the start planet (no message).
     */
    public function ReturnEvent()
    {
        $this->RestoreFleet();
    }

    /**
     * Counter-espionage chance in percent (OGame rule): min(round(2^(target tech - sender tech) * probes *
     * ships on the base * 0.0025), 100).
     */
    private function getDetectionChance(int $senderSpyTech, int $targetSpyTech, int $fleetAmount, int $totalShipDefCount) : int
    {
        // $targetChance = random_int(0, min(($fleetAmount / 4) * ($targetSpyTech / $senderSpyTech), 100));

        // New calculation: 1% Counterspy-Chance for 250 Ships * target spy bonus, if > sender spy tec
        // $targetChance = ($totalShipDefCount/250)*MAX($senderSpyTech-$targetSpyTech, 1) + (($fleetAmount / 4) * ($targetSpyTech / $senderSpyTech));

        // Calculation from https://ogame.fandom.com/wiki/Counterespionage
        $targetChance = min(round(pow(2, $targetSpyTech - $senderSpyTech) * $fleetAmount * $totalShipDefCount * 0.0025), 100);

        return $targetChance;
    }

    /**
     * Information classes the report may show for the given probe count (see MissionCaseSpy::TargetEvent for
     * the formula: minimum probes = +-(tech difference * SPY_DIFFENCE_FACTOR)^2, +1/+3/+5 * SPY_VIEW_FACTOR for
     * defense/buildings/research). Always includes resources (900).
     * @return array<int, array<int>> class id (900 resources, 200 ships, 400 defense, 0 buildings, 100 research) => element ids.
     */
    private function getClassIDs($fleetAmount, $senderSpyTech, $targetSpyTech) : array
    {
        $reslist = $this->state->reslist;
        $classIDs = [];

        $techDifference = abs($senderSpyTech - $targetSpyTech);
        $MinAmount = ($senderSpyTech > $targetSpyTech ? -1 : 1) * pow($techDifference * SPY_DIFFENCE_FACTOR, 2);
        $SpyFleet = $fleetAmount >= $MinAmount;
        $SpyDef = $fleetAmount >= $MinAmount + 1 * SPY_VIEW_FACTOR;
        $SpyBuild = $fleetAmount >= $MinAmount + 3 * SPY_VIEW_FACTOR;
        $SpyTechno = $fleetAmount >= $MinAmount + 5 * SPY_VIEW_FACTOR;

        $classIDs[900] = array_merge($reslist['resstype'][1]);

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

        return $classIDs;
    }
}
