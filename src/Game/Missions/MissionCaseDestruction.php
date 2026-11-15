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

use OutOfRangeException;
use App\Game\Core\StoredData;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\BattleEngine\BattleHandler;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\PlayerUtil;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\Singleton;

class MissionCaseDestruction extends MissionFunctions implements MissionInterface
{
    public function __construct($Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet = $Fleet;
    }

    public function TargetEvent()
    {
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;
        $db = $this->db;
        $config = $this->configs->get($this->_fleet['universe']);

        $fleetAttack = [];
        $fleetDefend = [];

        $userAttack = [];
        $userDefend = [];

        $incomingFleets = [];

        $stealResource = [
            901 => 0,
            902 => 0,
            903 => 0,
        ];

        $debris = [];
        $planetDebris = [];

        $debrisResource = [RESOURCE_METAL, RESOURCE_CRYSTAL];

        $messageHTML = $this->getMessageText();

        $sql = "SELECT * FROM %%PLANETS%% WHERE `id` = :planetId FOR UPDATE;";
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

        // select wreckfield to lock it, can be empty.
        // The repair dock can only be built on planets (uni1_vars.onPlanetType = 1), never on moons, so the wrecks of
        // a moon fight belong to the parent planet's dock. Battle, spy report and dock all use the parent planet id.
        if ($targetPlanet['planet_type'] == 3) {
            $sql = "SELECT * FROM %%PLANETS%% WHERE `id_luna` = :planetId;";
            $targetPlanetID = $db->selectSingle($sql, [
                ':planetId' => $this->_fleet['end_id']
            ], 'id');
        } else {
            $targetPlanetID = $targetPlanet['id'];
        }
        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% WHERE `planetId` = :planetId FOR UPDATE;";
        $db->selectSingle($sql, [
            ':planetId' => $targetPlanetID
        ]);

        $sql = "SELECT * FROM %%USERS%% WHERE `id` = :userId;";
        $targetUser = $db->selectSingle($sql, [
            ':userId'   => $targetPlanet['id_owner'],
        ]);

        $planetUpdater = new ResourceUpdate($this->db, $this->configs, $this->state);

        [$targetUser, $targetPlanet] = $planetUpdater->CalcResource(
            $targetUser,
            $targetPlanet,
            true,
            $this->_fleet['arrival_time']
        );

        if ($this->_fleet['group'] != 0) {
            $sql = "DELETE FROM %%AKS%% WHERE `id` = :acsId;";
            $db->delete($sql, [
                ':acsId'    => $this->_fleet['group'],
            ]);

            $sql = "SELECT * FROM %%FLEETS%% WHERE `group` = :acsId FOR UPDATE;";

            $incomingFleetsResult = $db->select($sql, [
                ':acsId'    => $this->_fleet['group'],
            ]);

            foreach ($incomingFleetsResult as $incomingFleetRow) {
                $incomingFleets[$incomingFleetRow['id']] = $incomingFleetRow;
            }

            unset($incomingFleetsResult);
        } else {
            $incomingFleets = [$this->_fleet['id'] => $this->_fleet];
        }

        $currentTotalFleetState = [];
        $mainAttacker = null;
        foreach ($incomingFleets as $fleetID => $fleetDetail) {
            $sql = "SELECT * FROM %%USERS%% WHERE `id` = :userId;";
            $fleetAttack[$fleetID]['player'] = $db->selectSingle($sql, [
                ':userId'   => $fleetDetail['owner']
            ]);

            $fleetAttack[$fleetID]['fleetDetail'] = $fleetDetail;
            $fleetAttack[$fleetID]['unit'] = $this->fleetFunctions()->unserialize($fleetDetail['ships']);

            if (!isset($currentTotalFleetState[$fleetDetail['owner']])) {
                $currentTotalFleetState[$fleetDetail['owner']] = $this->fleetFunctions()->calculateCurrentTotalFleetState($fleetDetail['owner']);
            }

            $userAttack[$fleetAttack[$fleetID]['player']['id']] = $fleetAttack[$fleetID]['player']['username'];

            if ($mainAttacker === null) {
                $mainAttacker = $fleetDetail['owner'];
            }
        }

        $sql = "SELECT * FROM %%FLEETS%%
            WHERE `mission` = :mission
            AND `end_id` = :fleetEndId
            AND (`arrival_time` < :timeStamp OR (`arrival_time` = :timeStamp AND `id` < :acsFleetId))
            AND `stay_time` >= :timeStamp FOR UPDATE;";

        $targetFleetsResult = $db->select($sql, [
            ':mission'      => MISSION_HOLD,
            ':fleetEndId'   => $this->_fleet['end_id'],
            ':timeStamp'    => $this->_fleet['arrival_time'],
            ':acsFleetId'   => $this->_fleet['id']
        ]);

        foreach ($targetFleetsResult as $fleetDetail) {
            $fleetID = $fleetDetail['id'];

            $sql = "SELECT * FROM %%USERS%% WHERE `id` = :userId;";
            $fleetDefend[$fleetID]['player'] = $db->selectSingle($sql, [
                ':userId'   => $fleetDetail['owner'],
            ]);

            $fleetDefend[$fleetID]['fleetDetail'] = $fleetDetail;
            $fleetDefend[$fleetID]['unit'] = $this->fleetFunctions()->unserialize($fleetDetail['ships']);

            if (!isset($currentTotalFleetState[$fleetDetail['owner']])) {
                $currentTotalFleetState[$fleetDetail['owner']] = $this->fleetFunctions()->calculateCurrentTotalFleetState($fleetDetail['owner']);
            }

            $userDefend[$fleetDefend[$fleetID]['player']['id']] = $fleetDefend[$fleetID]['player']['username'];
        }

        unset($targetFleetsResult);

        $fleetDefend[0]['player'] = $targetUser;
        $fleetDefend[0]['fleetDetail'] = [
            'start_galaxy'    => $targetPlanet['galaxy'],
            'start_system'    => $targetPlanet['system'],
            'start_planet'    => $targetPlanet['planet'],
            'start_type'      => $targetPlanet['planet_type'],
        ];

        $fleetDefend[0]['unit'] = [];

        foreach (array_merge($reslist['fleet'], $reslist['defense']) as $elementID) {
            if (empty($targetPlanet[$resource[$elementID]])) {
                continue;
            }

            $fleetDefend[0]['unit'][$elementID] = $targetPlanet[$resource[$elementID]];
        }

        $userDefend[$fleetDefend[0]['player']['id']] = $fleetDefend[0]['player']['username'];
        if (!isset($currentTotalFleetState[$targetUser['id']])) {
            $currentTotalFleetState[$targetUser['id']] = $this->fleetFunctions()->calculateCurrentTotalFleetState($targetUser['id']);
        }
        $fleetDefend[0]['currentTotalFleetState'] = $currentTotalFleetState[$targetUser['id']];

        $battleHandler = new BattleHandler(false, $this->db, $this->configs, $this->state);

        $combatResult = $battleHandler->engine->calculateAttack($fleetAttack, $fleetDefend, $this->_fleet['universe']);
        $reportID = md5(uniqid('', true) . $this->state->runtime->timestamp);
        if (!$combatResult) {
            $this->cancelFight($userAttack, $userDefend, $targetPlanet);
            return;
        }

        if ($config->noob_protection && $config->rebuild_protection) {
            $battleHandler->CheckRebuildProtection($currentTotalFleetState, $combatResult, $this->_fleet['arrival_time'], $config->noob_protection_time);
        }

        foreach ($fleetAttack as $fleetID => $fleetDetail) {
            $fleetArray = '';
            $totalCount = 0;

            $fleetDetail['unit'] = array_filter($fleetDetail['unit']);
            foreach ($fleetDetail['unit'] as $elementID => $amount) {
                $fleetArray .= $elementID . ',' . floatToString($amount) . ';';
                $totalCount += $amount;
            }

            if ($totalCount == 0) {
                if ($this->_fleet['id'] == $fleetID) {
                    $this->KillFleet();
                } else {
                    $sql = 'DELETE %%FLEETS%%, %%FLEETS_EVENT%%
                        FROM %%FLEETS%%
                        INNER JOIN %%FLEETS_EVENT%% ON `fleetID` = `id`
                        WHERE `id` = :fleetId;';

                    $db->delete($sql, [
                        ':fleetId'  => $fleetID,
                    ]);
                }

                $sql = 'UPDATE %%LOG_FLEETS%% SET `report_id` = :reportId, `status` = :fleetState WHERE `id` = :fleetId;';
                $db->update($sql, [
                    ':reportId'     => $reportID,
                    ':fleetId'      => $fleetID,
                    ':fleetState'   => FLEET_DESTROYED,
                ]);

                unset($fleetAttack[$fleetID]);
            } elseif ($totalCount > 0) {
                $sql = "UPDATE %%FLEETS%% f, %%LOG_FLEETS%% l SET
                    f.`ships` = :fleetData,
                    f.`ship_amount` = :fleetCount,
                    l.`ships` = :fleetData,
                    l.`ship_amount` = :fleetCount,
                    l.`report_id` = :reportId
                    WHERE f.`id` = :fleetId AND l.`id` = :fleetId;";

                $db->update($sql, [
                    ':fleetData'    => substr($fleetArray, 0, -1),
                    ':fleetCount'   => $totalCount,
                    ':fleetId'      => $fleetID,
                    ':reportId'     => $reportID,
                ]);
            } else {
                throw new OutOfRangeException("Negative Fleet amount ....");
            }
        }

        foreach ($fleetDefend as $fleetID => $fleetDetail) {
            if ($fleetID != 0) {
                // Stay fleet
                $fleetArray = '';
                $totalCount = 0;

                $fleetDetail['unit'] = array_filter($fleetDetail['unit']);

                foreach ($fleetDetail['unit'] as $elementID => $amount) {
                    $fleetArray .= $elementID . ',' . floatToString($amount) . ';';
                    $totalCount += $amount;
                }

                if ($totalCount == 0) {
                    $sql = 'DELETE %%FLEETS%%, %%FLEETS_EVENT%%
                        FROM %%FLEETS%%
                        INNER JOIN %%FLEETS_EVENT%% ON `fleetID` = `id`
                        WHERE `id` = :fleetId;';

                    $db->delete($sql, [
                        ':fleetId'  => $fleetID
                    ]);

                    $sql = 'UPDATE %%LOG_FLEETS%% SET `report_id` = :reportId, `status` = :fleetState WHERE `id` = :fleetId;';
                    $db->update($sql, [
                        ':reportId'     => $reportID,
                        ':fleetId'      => $fleetID,
                        ':fleetState'   => FLEET_DESTROYED,
                    ]);

                    unset($fleetDefend[$fleetID]);
                } elseif ($totalCount > 0) {
                    $sql = "UPDATE %%FLEETS%% f, %%LOG_FLEETS%% l SET
                        f.`ships` = :fleetData,
                        f.`ship_amount` = :fleetCount,
                        l.`ships` = :fleetData,
                        l.`ship_amount` = :fleetCount,
                        l.`report_id` = :reportId
                        WHERE f.`id` = :fleetId AND l.`id` = :fleetId;";

                    $db->update($sql, [
                        ':fleetData'    => substr($fleetArray, 0, -1),
                        ':fleetCount'   => $totalCount,
                        ':fleetId'      => $fleetID,
                        ':reportId'     => $reportID
                    ]);
                } else {
                    throw new OutOfRangeException("Negative Fleet amount ....");
                }
            } else {
                $params = [':planetId' => $this->_fleet['end_id']];

                // Planet fleet
                $fleetArray = [];
                foreach ($fleetDetail['unit'] as $elementID => $amount) {
                    $fleetArray[] = '`' . $resource[$elementID] . '` = :' . $resource[$elementID];
                    $params[':' . $resource[$elementID]] = $amount;
                }

                if (!empty($fleetArray)) {
                    $sql = 'UPDATE %%PLANETS%% SET ' . implode(', ', $fleetArray) . ' WHERE id = :planetId;';
                    $db->update($sql, $params);
                }
            }
        }

        if ($combatResult['won'] == ATTACKER) {
            $stealResource = $battleHandler->calculateSteal($fleetAttack, $targetPlanet, $this->_fleet['universe']);
        }

        if ($this->_fleet['end_type'] == TYPE_MOON) {
            // Use planet debris, if attack on moons
            $sql = "SELECT der_metal, der_crystal FROM %%PLANETS%% WHERE id_luna = :moonId;";
            $targetDebris = $db->selectSingle($sql, [
                ':moonId'   => $this->_fleet['end_id']
            ]);
            $targetPlanet['der_metal'] += $targetDebris['der_metal'];
            $targetPlanet['der_crystal'] += $targetDebris['der_crystal'];
        }

        foreach ($debrisResource as $elementID) {
            $debris[$elementID] = $combatResult['debris']['attacker'][$elementID]
                + $combatResult['debris']['defender'][$elementID];
            $planetDebris[$elementID] = $targetPlanet['der_' . $resource[$elementID]] + $debris[$elementID];
        }

        $reportInfo = [
            'thisFleet'             => $this->_fleet,
            'debris'                => $debris,
            'stealResource'         => $stealResource,
            'moonChance'            => null,
            'additionalChance'      => 0,
            'moonDestroy'           => true,
            'moonName'              => null,
            'moonDestroyChance'     => null,
            'moonDestroySuccess'    => null,
            'fleetDestroyChance'    => null,
            'fleetDestroySuccess'   => false,
        ];

        $units = $combatResult['unitLost']['attacker'] + $combatResult['unitLost']['defender'];

        switch ($combatResult['won']) {
            // Win
            case ATTACKER:
                // Moon destruction uses the RIPs of all surviving fleets of the attacking group (ACS);
                // $leadRips is only the lead fleet's own share (for its lost-RIP statistic).
                $rips = 0;
                foreach ($fleetAttack as $attackFleet) {
                    $rips += $attackFleet['unit'][SHIP_RIP] ?? 0;
                    $rips += $attackFleet['unit'][VOID_TITAN] ?? 0;
                }
                $leadUnits = $fleetAttack[$this->_fleet['id']]['unit'] ?? [];
                $leadRips = ($leadUnits[SHIP_RIP] ?? 0) + ($leadUnits[VOID_TITAN] ?? 0);

                $moonDestroyChance = round((100 - sqrt($targetPlanet['diameter'])) * sqrt($rips), 1);

                // Max 100% | Min 0%
                $moonDestroyChance = min($moonDestroyChance, 100);
                $moonDestroyChance = max($moonDestroyChance, 0);

                $randChance = random_int(1, 100);
                if ($randChance <= $moonDestroyChance) {
                    $sql = 'SELECT id FROM %%PLANETS%% WHERE id_luna = :moonId;';
                    $planetID = $db->selectSingle($sql, [
                        ':moonId'   => $targetPlanet['id'],
                    ], 'id');


                    $sql = 'UPDATE %%FLEETS%% SET
                        `start_type` = :startType,
                        `start_id` = :planetId
                        WHERE `start_id` = :moonId;';

                    $db->update($sql, [
                        ':startType' => TYPE_PLANET,
                        ':planetId' => $planetID,
                        ':moonId'   => $targetPlanet['id'],
                    ]);

                    $sql = 'UPDATE %%FLEETS%% SET
                        `end_type` = :endType,
                        `end_id` = :planetId,
                        `mission` = IF(`mission` = :dest, :att, `mission`)
                        WHERE `end_id` = :moonId
                        AND `id` != :fleetId;';

                    $db->update($sql, [
                        ':endType'  => TYPE_PLANET,
                        ':planetId' => $planetID,
                        ':moonId'   => $targetPlanet['id'],
                        ':fleetId'  => $this->_fleet['id'],
                        ':dest'     => MISSION_DESTRUCTION,
                        ':att'      => MISSION_ATTACK,
                    ]);

                    $sql = "UPDATE %%AKS%% SET `target` = :planetId WHERE `target` = :moonId;";
                    $db->update($sql, [
                        ':planetId' => $planetID,
                        ':moonId'   => $targetPlanet['id'],
                    ]);

                    $reportInfo['moonDestroySuccess'] = 1;
                } else {
                    $reportInfo['moonDestroySuccess'] = 0;
                }

                $fleetDestroyChance = round(sqrt($targetPlanet['diameter']) / 2);

                $randChance = random_int(1, 100);
                if ($randChance <= $fleetDestroyChance) {
                    if (isset($fleetAttack[$this->_fleet['id']])) {
                        $this->statsHandler->updateLostAdvancedStats($this->_fleet['owner'], $leadUnits);
                        $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::DESTROY_MOON_RIPS_LOST, $leadRips);
                    }
                    $this->KillFleet();
                    $reportInfo['fleetDestroySuccess'] = true;
                } else {
                    $reportInfo['fleetDestroySuccess'] = false;
                }

                $reportInfo['moonDestroyChance'] = $moonDestroyChance;
                $reportInfo['fleetDestroyChance'] = $fleetDestroyChance;

                $class = ['raportWin', 'raportLose'];

                $sql = "SELECT dpath, css_dpath FROM %%USERS_SETTINGS%% WHERE user_id = :id;";
                $theme = $db->select($sql, [
                    ':id' => $mainAttacker,
                ])[0];

                if ($theme['dpath'] == "SetSail" && $theme['css_dpath'] == "SetSail") {
                    $this->statsHandler->increment($mainAttacker, AdvancedStatsEnum::SET_SAIL_WINS, 1);
                }

                foreach ($userAttack as $attackerId => $fleet) {
                    $this->statsHandler->increment($attackerId, AdvancedStatsEnum::WONS, 1);
                    $this->statsHandler->setTimestamp($attackerId, AdvancedStatsEnum::FIRST_ATTACK_WIN, $this->_fleet['arrival_time']);
                    if ($units > 1000000) {
                        $this->statsHandler->setTimestamp($attackerId, AdvancedStatsEnum::FIRST_BIG_ATTACK_WIN, $this->_fleet['arrival_time']);
                    }
                }
                foreach ($userDefend as $defenderId => $fleet) {
                    $this->statsHandler->increment($defenderId, AdvancedStatsEnum::LOOS, 1);
                }
                break;
            case DEFENDER:
                // Lose
                $class = ['raportLose', 'raportWin'];
                $reportInfo['moonDestroySuccess'] = -1;

                foreach ($userAttack as $attackerId => $fleet) {
                    $this->statsHandler->increment($attackerId, AdvancedStatsEnum::LOOS, 1);
                }
                foreach ($userDefend as $defenderId => $fleet) {
                    $this->statsHandler->increment($defenderId, AdvancedStatsEnum::WONS, 1);
                    $this->statsHandler->setTimestamp($defenderId, AdvancedStatsEnum::FIRST_DEFENSE_WIN, $this->_fleet['arrival_time']);
                    if ($units > 1000000) {
                        $this->statsHandler->setTimestamp($defenderId, AdvancedStatsEnum::FIRST_BIG_DEFENSE_WIN, $this->_fleet['arrival_time']);
                    }
                }
                break;
            default:
                // Draw
                $class = ['raportDraw', 'raportDraw'];

                $reportInfo['moonDestroySuccess'] = -1;
                foreach ($userAttack as $attackerId => $fleet) {
                    $this->statsHandler->increment($attackerId, AdvancedStatsEnum::DRAWS, 1);
                }
                foreach ($userDefend as $defenderId => $fleet) {
                    $this->statsHandler->increment($defenderId, AdvancedStatsEnum::DRAWS, 1);
                }
                break;
        }

        $reportData = $battleHandler->GenerateReport($combatResult, $reportInfo, REAL_FIGHT,$fleetAttack, $fleetDefend, $battleHandler->engine->getName());

        $battleHandler->GenerateWreckField($targetPlanetID, $combatResult, $targetUser['id'], $this->_fleet['universe']);

        $sql = 'INSERT INTO %%RW%% SET
            `rid` = :reportId,
            `raport` = :reportData,
            `time` = :time,
            `attacker` = :attackers,
            `defender` = :defenders,
            `battleengine` = :battleengine;';

        $db->insert($sql, [
            ':reportId'     => $reportID,
            ':reportData'   => $battleHandler->engine->getName() === 'rust' ? compressToBase64(json_encode($reportData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : StoredData::encode($reportData),
            ':time'         => $this->_fleet['arrival_time'],
            ':attackers'    => implode(',', array_keys($userAttack)),
            ':defenders'    => implode(',', array_keys($userDefend)),
            ':battleengine' => $battleHandler->engine->getName()
        ]);

        $i = 0;
        $units = $combatResult['unitLost']['attacker'] + $combatResult['unitLost']['defender'];
        $universe = $this->_fleet['universe'];
        $topkb = $this->checkForTopKB($units, $universe);

        foreach ([$userAttack, $userDefend] as $data) {
            foreach ($data as $userID => $userName) {
                $LNG = $this->getLanguage(null, $userID);

                $message = sprintf(
                    $messageHTML,
                    $reportID,
                    $class[$i],
                    $LNG['sys_mess_attack_report'],
                    sprintf(
                        $LNG['sys_adress_planet'],
                        $this->_fleet['end_galaxy'],
                        $this->_fleet['end_system'],
                        $this->_fleet['end_planet']
                    ),
                    $LNG['type_planet_short_' . $this->_fleet['end_type']],
                    $LNG['sys_lost'],
                    $class[0],
                    $LNG['sys_attack_attacker_pos'],
                    $this->viewHelpers()->prettyNumber($combatResult['unitLost']['attacker']),
                    $class[1],
                    $LNG['sys_attack_defender_pos'],
                    $this->viewHelpers()->prettyNumber($combatResult['unitLost']['defender']),
                    $LNG['sys_gain'],
                    $LNG['tech'][901],
                    $this->viewHelpers()->prettyNumber($stealResource[901]),
                    $LNG['tech'][902],
                    $this->viewHelpers()->prettyNumber($stealResource[902]),
                    $LNG['tech'][903],
                    $this->viewHelpers()->prettyNumber($stealResource[903]),
                    $LNG['sys_debris'],
                    $LNG['tech'][901],
                    $this->viewHelpers()->prettyNumber($debris[901]),
                    $LNG['tech'][902],
                    $this->viewHelpers()->prettyNumber($debris[902])
                );

                $this->playerUtil()->sendMessage(
                    $userID,
                    0,
                    $LNG['sys_mess_tower'],
                    MESSAGE_TYPE_BATTLE_REPORT,
                    $LNG['sys_mess_attack_report'],
                    $message,
                    $this->_fleet['arrival_time'],
                    1,
                    $this->_fleet['universe'],
                    $this->_fleet['end_id']
                );

                if ($topkb) {
                    $sql = "INSERT INTO %%TOPKB_USERS%% SET
                        `rid` = :reportId,
                        `role` = :userRole,
                        `username` = :username,
                        `uid` = :userId;";

                    $db->insert($sql, [
                        ':reportId' => $reportID,
                        ':userRole' => $i + 1,
                        ':username' => $userName,
                        ':userId'   => $userID,
                    ]);
                }
            }

            $i++;
        }

        if ($this->_fleet['end_type'] == TYPE_MOON) {
            $debrisType = 'id_luna';
        } else {
            $debrisType = 'id';
        }

        $sql = "UPDATE %%PLANETS%% SET
            `der_metal`	= :metal,
            `der_crystal`	= :crystal,
            `tf_active` = :new_debris
            WHERE $debrisType = :planetId;";

        $db->update($sql, [
            ':metal'    => $planetDebris[901],
            ':crystal'  => $planetDebris[902],
            ':new_debris' => 1,
            ':planetId' => $this->_fleet['end_id'],
        ]);

        if ($reportInfo['moonDestroySuccess'] == 1) {
            $this->playerUtil()->deletePlanet($targetPlanet['id']);
            $this->statsHandler->increment($targetUser['id'], AdvancedStatsEnum::MOONS_LOST, 1);
            $this->statsHandler->increment($this->_fleet['owner'], AdvancedStatsEnum::MOONS_DESTROYED, 1);
        } else {
            $sql = "UPDATE %%PLANETS%% SET
                `metal` = `metal` - :metal,
                `crystal` = `crystal` - :crystal,
                `deuterium` = `deuterium` - :deuterium
                WHERE `id` = :planetId;";

            $db->update($sql, [
                ':metal'        => $stealResource[901],
                ':crystal'      => $stealResource[902],
                ':deuterium'    => $stealResource[903],
                ':planetId'     => $this->_fleet['end_id'],
            ]);
        }

        if ($topkb) {
            $sql = "INSERT INTO %%TOPKB%% SET
                `units` = :units,
                `rid` = :reportId,
                `time` = :time,
                `universe` = :universe,
                `galaxy` = :galaxy,
                `result` = :result;";

            $db->insert($sql, [
                ':units'    => $units,
                ':reportId' => $reportID,
                ':time'     => $this->_fleet['arrival_time'],
                ':galaxy'   => $this->_fleet['end_galaxy'],
                ':universe' => $this->_fleet['universe'],
                ':result'   => $combatResult['won'],
            ]);
        }

        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
    }

    public function EndStayEvent()
    {
        return;
    }

    public function ReturnEvent()
    {
        $this->sendAttackReturnMessage();

        $this->RestoreFleet();
    }

    private function cancelFight($userAttack, $userDefend, $targetPlanet)
    {
        foreach ([$userAttack, $userDefend] as $data) {
            foreach ($data as $userID => $userName) {
                $LNG = $this->getLanguage(null, $userID);

                $message = sprintf(
                    $LNG['sys_mess_attack_cancel'],
                    $targetPlanet['name'],
                    GetTargetAddressLink($this->_fleet),
                );

                $this->playerUtil()->sendMessage(
                    $userID,
                    0,
                    $LNG['sys_mess_tower'],
                    MESSAGE_TYPE_BATTLE_REPORT,
                    $LNG['sys_mess_attack_report'],
                    $message,
                    $this->state->runtime->timestamp,
                    1,
                    $this->_fleet['universe'],
                    $this->_fleet['end_id']
                );
            }
        }

        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
    }

    private function getMessageText()
    {
        $messageHTML = <<<HTML
<div class="raportMessage">
	<table>
		<tr>
			<td colspan="2">
			<a href="game?page=raport&raport=%s" target="_blank"><span class="%s">%s %s (%s)</span></a>
			</td>
		</tr>
		<tr>
			<td>%s</td><td><span class="%s">%s: %s</span>&nbsp;<span class="%s">%s: %s</span></td>
		</tr>
		<tr>
			<td>%s</td>
			<td>
                <span>%s:&nbsp;<span class="reportSteal element901">%s</span>&nbsp;</span>
                <span>%s:&nbsp;<span class="reportSteal element902">%s</span>&nbsp;</span>
                <span>%s:&nbsp;<span class="reportSteal element903">%s</span></span>
			</td>
		</tr>
		<tr>
			<td>%s</td>
			<td>
                <span>%s:&nbsp;<span class="reportDebris element901">%s</span>&nbsp;</span>
                <span>%s:&nbsp;<span class="reportDebris element902">%s</span></span>
			</td>
		</tr>
	</table>
</div>
HTML;
        //Minize HTML
        return str_replace(["\n", "\t", "\r"], "", $messageHTML);
    }
}
