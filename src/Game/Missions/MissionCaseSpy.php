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
use App\Game\Core\ResourceUpdate;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;

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
        $reslist = & $this->state->reslist;
        $resource = & $this->state->resource;
        $planetfleet = [];
        $resourceIDs = $reslist['fleet'];

        foreach ($resourceIDs as $shiptype) {
            $planetfleet[$shiptype] = $targetPlanet[$resource[$shiptype]];
        }

        return $planetfleet;
    }

    private function getPlanetDefense($targetPlanet)
    {
        $reslist = & $this->state->reslist;
        $resource = & $this->state->resource;
        $planetdef = [];
        $resourceIDs = $reslist['defense'];

        foreach ($resourceIDs as $deftype) {
            $planetdef[$deftype] = $targetPlanet[$resource[$deftype]];
        }

        return $planetdef;
    }

    public function TargetEvent()
    {
        $pricelist = & $this->state->pricelist;
        $reslist = & $this->state->reslist;
        $resource = & $this->state->resource;
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

        $LNG = $this->getLanguage($senderUser['lang']);

        $planetUpdater = new ResourceUpdate($this->db, $this->configs, $this->state);
        [$targetUser, $targetPlanet] = $planetUpdater->CalcResource(
            $targetUser,
            $targetPlanet,
            true,
            $this->_fleet['arrival_time']
        );

        $sql = 'SELECT * FROM %%FLEETS%%
            WHERE `end_id` = :planetId
            AND `mission` = :mission
            AND `arrival_time` <= :time
            AND `stay_time` >= :time FOR UPDATE;';

        $targetStayFleets = $db->select($sql, [
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

        $fleetAmount = $this->_fleet['ship_amount'];

        $senderSpyTech = max($senderUser['spy_tech'], 1);
        $targetSpyTech = max($targetUser['spy_tech'], 1);

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

        // what is seen by the 'attacker' is already calculated above.
        // if target planet does not have fleet or def, it is safe to set $targetChance to -1
        // so probes are no longer destroyed by thin air or something

        $totalShipDefCount = 0;
        foreach (array_values($this->getPlanetFleet($targetPlanet)) as $ships) {
            $totalShipDefCount += $ships;
        }

        // $targetChance = random_int(0, min(($fleetAmount / 4) * ($targetSpyTech / $senderSpyTech), 100));

        // New calculation: 1% Counterspy-Chance for 250 Ships * target spy bonus, if > sender spy tec
        // $targetChance = ($totalShipDefCount/250)*MAX($senderSpyTech-$targetSpyTech, 1) + (($fleetAmount / 4) * ($targetSpyTech / $senderSpyTech));

        // Calculation from https://ogame.fandom.com/wiki/Counterespionage
        $targetChance = min(round(pow(2, $targetSpyTech - $senderSpyTech) * $fleetAmount * $totalShipDefCount * 0.0025), 100);

        $spyChance = random_int(1, 100);
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

        // show ships beeing repaired
        $repair_order = [];
        if ($SpyFleet && $this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK)) {
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
                $repair_order = unserialize($repairing);
                foreach ($repair_order as $elementID => $amount) {
                    if (!isset($spyData[200][$elementID])) {
                        $spyData[200][$elementID] = 0;
                    }
                }
            }
        }

        $battleHandler = new BattleHandler(false, $this->db, $this->configs, $this->state);

        $sumPlanetRessources = 2 * ($targetPlanet[$resource[RESOURCE_METAL]] + $targetPlanet[$resource[RESOURCE_CRYSTAL]] + $targetPlanet[$resource[RESOURCE_DEUT]]);
        $simulated = [];
        $simulated['fleetDetail']['resource_metal'] = 0;
        $simulated['fleetDetail']['resource_crystal'] = 0;
        $simulated['fleetDetail']['resource_deuterium'] = 0;
        $simulated['unit'] = [
            '203' => ceil($sumPlanetRessources / $pricelist[SHIP_LARGE_CARGO]['capacity']),
        ];
        $fleetSimulate[] = $simulated;

        $capacityNeeded = $battleHandler->calculateMinCapacity([
            'metal' => getNumber($targetPlanet[$resource[RESOURCE_METAL]]),
            'crystal' => getNumber($targetPlanet[$resource[RESOURCE_CRYSTAL]]),
            'deuterium' => getNumber($targetPlanet[$resource[RESOURCE_DEUT]]),
        ]);
        $smallCargoNeeded = ceil($capacityNeeded / $pricelist[SHIP_SMALL_CARGO]['capacity']);
        $largeCargoNeeded = ceil($capacityNeeded / $pricelist[SHIP_LARGE_CARGO]['capacity']);

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

        $LNG = $this->getLanguage($targetUser['lang']);

        $sourceLink = sprintf(
            $LNG['sys_mess_spy_link'],
            $senderPlanet['name'],
            $senderUser['username'],
            $this->_fleet['start_galaxy'],
            $this->_fleet['start_system'],
            $this->_fleet['start_planet'],
        );

        $targetLink = sprintf(
            $LNG['sys_mess_spy_link'],
            $targetPlanet['name'],
            $LNG['type_planet_short_' . $targetPlanet['planet_type']],
            $this->_fleet['end_galaxy'],
            $this->_fleet['end_system'],
            $this->_fleet['end_planet'],
        );

        $targetMessage = sprintf(
            $LNG['sys_mess_spy_ennemyfleet_' . $senderPlanet['planet_type']],
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

        if ($totalShipDefCount > 0 && $targetChance >= $spyChance) {
            $config = $this->configs->get($this->_fleet['universe']);
            $whereCol = $this->_fleet['end_type'] == 3 ? "id_luna" : "id";

            $sql = 'UPDATE %%PLANETS%% SET
			der_metal   = der_metal + :metal,
			der_crystal = der_crystal + :crystal,
            tf_active = :new_debris
			WHERE ' . $whereCol . ' = :planetId;';

            $db->update($sql, [
                ':metal'    => $fleetAmount * $pricelist[SHIP_PROBE]['cost'][RESOURCE_METAL] * $config->fleet_debris_percentage / 100,
                ':crystal'  => $fleetAmount * $pricelist[SHIP_PROBE]['cost'][RESOURCE_CRYSTAL] * $config->fleet_debris_percentage / 100,
                ':new_debris' => 1,
                ':planetId' => $this->_fleet['end_id'],
            ]);

            $this->statsHandler->updateDestroyedAdvancedStats($this->_fleet['target_owner'], $this->_fleet['owner'], SHIP_PROBE, $fleetAmount);

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
        $CombatCaps = & $this->state->CombatCaps;
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
        $pricelist = & $this->state->pricelist;

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

    public function estimateRecyclers($recycleValue)
    {
        $pricelist = & $this->state->pricelist;
        return ceil($recycleValue / $pricelist[SHIP_RECYCLER]["capacity"]) + 1;
    }

    public function EndStayEvent()
    {
        return;
    }

    public function ReturnEvent()
    {
        $this->RestoreFleet();
    }
}
