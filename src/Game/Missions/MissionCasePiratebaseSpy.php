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

/**
 * Mission "pirate base espionage" (21): probes spy on an NPC pirate base and deliver a spy report like
 * MissionCaseSpy, with the base's resources and ships (no buildings/defense; the research shown is the
 * sender's own weapon/shield/armor tech minus 3). The base counts as having spy tech sender - 1 (min 1).
 */
class MissionCasePiratebaseSpy extends MissionFunctions implements MissionInterface
{
    use SpyMissionSupport;

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
        $db = $this->db;

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
        $spyData = $this->collectSpyData($classIDs, $senderUser, $targetBase, $targetFleet, $totalShipDefCount);

        $targetChance = (int) $this->getDetectionChance($senderSpyTech, $targetSpyTech, $fleetAmount, $totalShipDefCount);
        $spyChance = random_int(1, 100);

        [$smallCargoNeeded, $largeCargoNeeded] = $this->cargoNeeded($targetBase);

        // Scavengers Tool Box
        $stbSettings = $this->playerLookup()->player_stb_settings($senderUser);

        // determine if the spy report detects ships or deff
        $danger = $this->getDangerValue($spyData);

        $reportData = [
            'targetUserName'    => $LNG['type_planet_4'],
            'time'              => $this->_fleet['stay_time'],
            'stbEnabled'        => $stbSettings['stb_enabled'],
            'danger'            => $danger,
            'dangerClass'       => $danger > 0 ? 'danger' : 'nonedanger',
            'smallCargoNeeded'  => $smallCargoNeeded,
            'largeCargoNeeded'  => $largeCargoNeeded,
            'energy'            => 0, // a base has no energy to show
            'energyClass'       => '',
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

        $this->sendReport($reportData, $LNG);

        if ($totalShipDefCount > 0 && $targetChance >= $spyChance) {
            $this->destroyProbes($fleetAmount);
        } else {
            $this->setState(FLEET_RETURN);
            $this->SaveFleet();
        }
    }

    /**
     * Report data of the visible classes: the base's stock (resources, always), its ships (zero for types it does not
     * have; only enabled ones), and an estimate of the research (sender's own military/shield/defence tech minus 3,
     * not below 0). The sender's "only show what exists" setting drops zero entries.
     *
     * @param array<int, array<int>> $classIDs see getClassIDs()
     * @param array<int, int|float|string> $targetFleet ships of the base by element id
     * @param int|float $totalShipDefCount out: number of ships on the base (the counter-espionage chance depends on it)
     * @param array<mixed> $senderUser
     * @param array<mixed> $targetBase
     * @return array<int, array<int, int|float|string>> class id => element id => amount
     */
    private function collectSpyData(array $classIDs, array $senderUser, array $targetBase, array $targetFleet, int|float &$totalShipDefCount): array
    {
        $e = $this->elements();
        $spyData = [];
        foreach ($classIDs as $classID => $elementIDs) {
            if ($classID == 900) {
                foreach ($elementIDs as $elementID) {
                    if (isset($targetBase[$e->column($elementID)])) {
                        $spyData[$classID][$elementID] = $targetBase[$e->column($elementID)];
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
                $spyData[$classID][MILITARY_TECH] = max($senderUser[$e->column(MILITARY_TECH)] - 3, 0);
                $spyData[$classID][SHIELD_TECH] = max($senderUser[$e->column(SHIELD_TECH)] - 3, 0);
                $spyData[$classID][DEFENCE_TECH] = max($senderUser[$e->column(DEFENCE_TECH)] - 3, 0);
            }

            if ($senderUser['spyMessagesMode'] == 1 && isset($spyData[$classID])) {
                $spyData[$classID] = array_filter($spyData[$classID]);
            }
        }

        return $spyData;
    }

    /**
     * Detected probes: their debris (fleet_debris_percentage of the probe cost) is added to the base's debris field,
     * the destroyed-ships stat is counted (against nobody: owner 0), the fleet is deleted.
     */
    private function destroyProbes(int|float|string $fleetAmount): void
    {
        $e = $this->elements();
        $config = $this->configs->get($this->_fleet['universe']);

        $sql = 'UPDATE %%PIRATE_BASES%% SET
                `der_metal`   = `der_metal` + :metal,
                `der_crystal` = `der_crystal` + :crystal
                WHERE `id` = :baseId;';

        $this->db->update($sql, [
            ':metal'    => $fleetAmount * $e->cost(SHIP_PROBE)->metal * $config->fleet_debris_percentage / 100,
            ':crystal'  => $fleetAmount * $e->cost(SHIP_PROBE)->crystal * $config->fleet_debris_percentage / 100,
            ':baseId' => $this->_fleet['end_id'],
        ]);

        $this->statsHandler->updateDestroyedAdvancedStats(0, $this->_fleet['owner'], SHIP_PROBE, $fleetAmount);

        $this->KillFleet();
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
     * Information classes the report may show for the given probe count (see MissionCaseSpy::TargetEvent for
     * the formula: minimum probes = +-(tech difference * SPY_DIFFENCE_FACTOR)^2, +1/+3/+5 * SPY_VIEW_FACTOR for
     * defense/buildings/research). Always includes resources (900).
     * @return array<int, array<int>> class id (900 resources, 200 ships, 400 defense, 0 buildings, 100 research) => element ids.
     */
    private function getClassIDs($fleetAmount, $senderSpyTech, $targetSpyTech): array
    {
        $e = $this->elements();
        $classIDs = [];

        $techDifference = abs($senderSpyTech - $targetSpyTech);
        $MinAmount = ($senderSpyTech > $targetSpyTech ? -1 : 1) * pow($techDifference * SPY_DIFFENCE_FACTOR, 2);
        $SpyFleet = $fleetAmount >= $MinAmount;
        $SpyDef = $fleetAmount >= $MinAmount + 1 * SPY_VIEW_FACTOR;
        $SpyBuild = $fleetAmount >= $MinAmount + 3 * SPY_VIEW_FACTOR;
        $SpyTechno = $fleetAmount >= $MinAmount + 5 * SPY_VIEW_FACTOR;

        $classIDs[900] = array_merge($e->resourceIds(1));

        if ($SpyFleet) {
            $classIDs[200] = $e->ids('fleet');
        }

        if ($SpyDef) {
            $classIDs[400] = array_merge($e->ids('defense'), $e->ids('missile'));
        }

        if ($SpyBuild) {
            $classIDs[0] = $e->ids('build');
        }

        if ($SpyTechno) {
            $classIDs[100] = $e->ids('tech');
        }

        return $classIDs;
    }
}
