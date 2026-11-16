<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Session;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/** Fleet dispatch, step 1: target coordinates, speed and shortcuts (modes: show, saveShortcuts, checkTarget). */
#[\App\Routing\GamePage('game.php', 'fleetStep1')]
#[\App\Routing\GamePage('game.php', 'fleetStep1', uniPrefix: true)]
class FleetStep1Controller extends AbstractGameController
{
    public static $requireModule = 'MODULE_FLEET_TABLE';

    public function initialize(): void
    {
        parent::initialize();
    }

    public function show(): Response
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $pricelist = $this->state->pricelist;
        $reslist = $this->state->reslist;
        $LNG = $this->state->LNG;
        $resource = $this->state->resource;
        $targetGalaxy = $this->http()->_GP('galaxy', (int) $PLANET['galaxy']);
        $targetSystem = $this->http()->_GP('system', (int) $PLANET['system']);
        $targetPlanet = $this->http()->_GP('planet', (int) $PLANET['planet']);
        $targetType = $this->http()->_GP('type', (int) $PLANET['planet_type']);

        $mission = $this->http()->_GP('target_mission', -1);

        $Fleet = [];
        $FleetRoom = 0;
        foreach ($reslist['fleet'] as $id => $ShipID) {
            $amount = max(0, round($this->http()->_GP('ship' . $ShipID, 0.0, 0.0)));

            if ($amount < 1 || $ShipID == 212) {
                continue;
            }

            $Fleet[$ShipID] = $amount;
            $FleetRoom += $pricelist[$ShipID]['capacity'] * $amount;
        }

        if (empty($Fleet)) {
            $this->fleetFunctions()->gotoFleetPage();
        }

        $FleetData = [
            'fleetroom'         => floatToString($FleetRoom),
            'gamespeed'         => $this->fleetFunctions()->getGameSpeedFactor(),
            'fleetspeedfactor'  => 1,
            'planet'            => [
                'galaxy' => $PLANET['galaxy'],
                'system' => $PLANET['system'],
                'planet' => $PLANET['planet'],
                'planet_type' => $PLANET['planet_type'],
            ],
            'maxspeed'          => $this->fleetFunctions()->getFleetMaxSpeed($Fleet, $USER),
            'ships'             => $this->fleetFunctions()->getFleetShipInfo($Fleet, $USER),
            'fleetMinDuration'  => MIN_FLEET_TIME,
        ];

        $token = getRandomString();
        $session = $this->sessions()->load();
        $fleet = $session->fleet;
        $fleet[$token] = [
            'ownPlanet' => $PLANET['id'],
            'time'      => $this->state->runtime->timestamp,
            'fleet'     => $Fleet,
            'fleetRoom' => $FleetRoom,
            'predefinedResources' => [
                'met' =>  $this->http()->_GP('met_storage', ""),
                'krist' =>  $this->http()->_GP('krist_storage', ""),
                'deut' =>  $this->http()->_GP('deut_storage', ""),
            ],
        ];
        $session->fleet = $fleet;
        $session->save();

        $shortcutList = $this->GetUserShotcut();
        $colonyList = $this->GetColonyList();
        $ACSList = $this->GetAvalibleACS();

        if (!empty($shortcutList)) {
            $shortcutAmount = max(array_keys($shortcutList));
        } else {
            $shortcutAmount = 0;
        }

        $this->addScript('flotten.js');

        $config = $this->configs->get();

        $pirateBase = null;
        if ($this->viewHelpers()->isModuleAvailable(MODULE_PIRATE_BASES)) {
            $db = $this->db;
            $sql = "SELECT `galaxy`, `system` FROM %%PIRATE_BASES%% 
                WHERE `id_owner` = :owner";
            $pirateBase = $db->selectSingle($sql, [
                ':owner'   => $USER['id'],
            ]);
        }

        $this->assign([
            'token'         => $token,
            'mission'       => $mission,
            'shortcutList'  => $shortcutList,
            'shortcutMax'   => $shortcutAmount,
            'colonyList'    => $colonyList,
            'pirateBase'    => $pirateBase,
            'ACSList'       => $ACSList,
            'galaxy'        => $targetGalaxy,
            'system'        => $targetSystem,
            'planet'        => $targetPlanet,
            'type'          => $targetType,
            'honorboost'    => $this->playerUtil()->calculate_honorpoints_boost($USER['id']),
            'speedSelect'   => FleetFunctions::$allowedSpeed,
            'typeSelect'    => [1 => $LNG['type_planet_1'], 2 => $LNG['type_planet_2'], 3 => $LNG['type_planet_3']],
            'fleetdata'     => $FleetData,
            'config'        => [
                'uni_type'                  => $config->uni_type,
                'galaxy_type'               => $config->galaxy_type,
                'max_galaxy'                => $config->max_galaxy,
                'max_system'                => $config->max_system,
                'max_planets'               => $config->max_planets,
                'galaxy_distance_factor'    => $config->galaxy_distance_factor,
                'system_distance_factor'    => $config->system_distance_factor,
                'system_distance'           => $config->system_distance,
                'planet_distance_factor'    => $config->planet_distance_factor,
                'planet_distance'           => $config->planet_distance,
            ],
        ]);

        //check if all ships are available
        foreach ($Fleet as $Ship => $Count) {
            // Count ships in other session tokens
            // foreach ($fleet as $tokenID => $sessionData) {
            //     if ($tokenID == $token || (isset($sessionData['ownPlanet']) && $sessionData['ownPlanet'] != $PLANET['id'])) {
            //         continue;
            //     }
            //     if (isset($sessionData['fleet'][$Ship])) {
            //         $Count += $sessionData['fleet'][$Ship];
            //     }
            // }
            if ($Count > $PLANET[$resource[$Ship]]) {
                $this->printMessage($LNG['fl_not_all_ship_avalible'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetTable',
                ]]);
            }
        }
        return $this->render('game/page.fleetStep1.default.html.twig');
    }

    public function saveShortcuts(): void
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $db = $this->db;

        $ShortcutData = $this->state->requestInput['shortcut'] ?? [];
        $ShortcutUser = $this->GetUserShotcut();

        foreach ($ShortcutUser as $ID => $shortcut) {
            if (!isset($ShortcutData[$ID])) {
                $sql = "DELETE FROM %%SHORTCUTS%% WHERE `shortcutID` = :shortcutID AND `ownerID` = :userID;";
                $db->delete($sql, [
                    ':userID' => $USER['id'],
                    ':shortcutID' => $ID,
                ]);
            }
        }

        if (!isset($this->state->requestInput['shortcut'])) {
            $this->sendJSON($LNG['fl_shortcut_saved']);
        }

        foreach ($ShortcutData as $ID => $planetData) {
            if (empty($planetData['name']) || empty($planetData['galaxy']) || empty($planetData['system']) || empty($planetData['planet'])) {
                continue;
            }
            if (strpos($ID, 'new') !== false) {
                $sql = "INSERT INTO %%SHORTCUTS%% SET 
                    `ownerID`   = :userID,
                    `name`      = :name,
                    `galaxy`    = :galaxy,
                    `system`    = :system,
                    `planet`    = :planet,
                    `type`      = :type;";
                $db->insert($sql, [
                    ':userID' => $USER['id'],
                    ':name' => substr($planetData['name'], 0, 32),
                    ':galaxy' => $planetData['galaxy'],
                    ':system' => $planetData['system'],
                    ':planet' => $planetData['planet'],
                    ':type' => $planetData['type'],
                ]);
            } else {
                $planetData['ownerID'] = $USER['id'];
                $planetData['shortcutID'] = $ID;
                if ($planetData != $ShortcutUser[$ID]) {
                    $sql = "UPDATE %%SHORTCUTS%% SET 
                        `name`      = :name,
                        `galaxy`    = :galaxy,
                        `system`    = :system,
                        `planet`    = :planet,
                        `type`      = :type 
                        WHERE `shortcutID` = :shortcutID AND `ownerID` = :userID;";
                    $db->update($sql, [
                        ':userID' => $USER['id'],
                        ':name' => substr($planetData['name'], 0, 32),
                        ':galaxy' => $planetData['galaxy'],
                        ':system' => $planetData['system'],
                        ':planet' => $planetData['planet'],
                        ':type' => $planetData['type'],
                        ':shortcutID' => $ID,
                    ]);
                }
            }
        }

        $this->sendJSON($LNG['fl_shortcut_saved']);
    }

    private function GetColonyList()
    {
        $PLANET = $this->state->PLANET;
        $USER = $this->state->USER;
        $ColonyList = [];

        foreach ($USER['PLANETS'] as $CurPlanetID => $CurPlanet) {
            if ($PLANET['id'] == $CurPlanet['id']) {
                continue;
            }

            $ColonyList[] = [
                'name'      => $CurPlanet['name'],
                'galaxy'    => $CurPlanet['galaxy'],
                'system'    => $CurPlanet['system'],
                'planet'    => $CurPlanet['planet'],
                'type'      => $CurPlanet['planet_type'],
            ];
        }

        return $ColonyList;
    }

    private function GetUserShotcut()
    {
        $USER = $this->state->USER;

        if (!$this->viewHelpers()->isModuleAvailable(MODULE_SHORTCUTS)) {
            return [];
        }

        $db = $this->db;

        $sql = "SELECT * FROM %%SHORTCUTS%% WHERE `ownerID` = :userID;";
        $ShortcutResult = $db->select($sql, [':userID'   => $USER['id']]);

        $ShortcutList = [];

        foreach ($ShortcutResult as $ShortcutRow) {
            $ShortcutList[$ShortcutRow['shortcutID']] = $ShortcutRow;
        }

        return $ShortcutList;
    }

    private function GetAvalibleACS()
    {
        $USER = $this->state->USER;

        $db = $this->db;

        $ACSList = [];

        $sql = "SELECT acs.`id`, acs.`public_id`, acs.`name`, planet.`galaxy`, planet.`system`, planet.`planet`, planet.`planet_type`
            FROM %%USERS_TO_ACS%%
            INNER JOIN %%AKS%% acs ON `acsID` = acs.`id`
            INNER JOIN %%PLANETS%% planet ON planet.`id` = acs.`target`
            WHERE `userID` = :userID AND :maxFleets > (SELECT COUNT(*) FROM %%FLEETS%% WHERE `group` = acsID);";
        $ACSResult = $db->select($sql, [
            ':userID' => $USER['id'],
            ':maxFleets' => $this->configs->get()->max_fleets_per_acs,
        ]);

        if (!empty($ACSResult)) {
            foreach ($ACSResult as $ACSRow) {
                $sql = "SELECT `acsID`
                    FROM %%USERS_TO_ACS%%
                    WHERE `userID` = :userID AND `acsID` = :aksID
                      AND ( :maxParticipants > (SELECT COUNT(DISTINCT `owner`) FROM %%FLEETS%% WHERE `group` = :aksID)
                       OR 1 = (SELECT COUNT(DISTINCT `owner`) FROM %%FLEETS%% WHERE `group` = :aksID AND `owner` = :userID) );";
                $ACSResult2 = $db->select($sql, [
                    ':userID' => $USER['id'],
                    ':aksID' => $ACSRow['id'],
                    ':maxParticipants' => $this->configs->get()->max_participants_per_acs,
                ]);

                if (!empty($ACSResult2)) {
                    $ACSList[] = $ACSRow;
                }
            }
        }

        return $ACSList;
    }

    public function checkTarget(): void
    {
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $resource = $this->state->resource;
        $targetGalaxy = $this->http()->_GP('galaxy', 0);
        $targetSystem = $this->http()->_GP('system', 0);
        $targetPlanet = $this->http()->_GP('planet', 0);
        $targetPlanetType = $this->http()->_GP('planet_type', 1);
        $koloShipInFleet = $this->http()->_GP('kolo', 0);

        if (
            $targetGalaxy == $PLANET['galaxy'] && $targetSystem == $PLANET['system']
            && $targetPlanet == $PLANET['planet'] && $targetPlanetType == $PLANET['planet_type']
        ) {
            $this->sendJSON($LNG['fl_error_same_planet']);
        }

        if ($targetPlanet == $this->configs->get()->max_planets + 3) {
            $db = $this->db;
            $sql = "SELECT * FROM %%PIRATE_BASES%% 
                WHERE `galaxy` = :galaxy AND `system` = :system AND `id_owner` = :userId";
            $pirateBase = $db->selectSingle($sql, [
                ':galaxy'   => $targetGalaxy,
                ':system'   => $targetSystem,
                ':userId'   => $USER['id'],
            ]);

            if (!isset($pirateBase)) {
                $this->sendJSON($LNG['fl_error_not_avalible']);
            }

            if ($targetPlanetType == 2 && ($pirateBase['der_metal'] + $pirateBase['der_crystal']) == 0) {
                $this->sendJSON($LNG['fl_error_empty_derbis']);
            }
        }
        // If target is not expedition
        elseif ($targetPlanet != $this->configs->get()->max_planets + 1) {
            $db = $this->db;
            $sql = "SELECT u.`id`, u.`urlaubs_modus`, u.`user_lastip`, u.`authattack`,
            	p.`destruyed`, p.`der_metal`, p.`der_crystal`, p.`tf_active`, p.`destruyed`
                FROM %%USERS%% as u, %%PLANETS%% as p 
                WHERE p.`universe` = :universe
                  AND p.`galaxy` = :targetGalaxy
                  AND p.`system` = :targetSystem
                  AND p.`planet` = :targetPlanet
                  AND p.`planet_type` = :targetType
                  AND u.`id` = p.`id_owner`;";

            $planetData = $db->selectSingle($sql, [
                ':universe' => $this->universe()->current(),
                ':targetGalaxy' => $targetGalaxy,
                ':targetSystem' => $targetSystem,
                ':targetPlanet' => $targetPlanet,
                ':targetType' => (($targetPlanetType == 2) ? 1 : $targetPlanetType),
            ]);

            if ($targetPlanetType == 3 && !isset($planetData)) {
                $this->sendJSON($LNG['fl_error_no_moon']);
            }

            if ($targetPlanetType == 1 && !isset($planetData) && !$koloShipInFleet) {
                $this->sendJSON($LNG['fl_error_not_avalible']);
            }

            if ($targetPlanetType != 2 && !empty($planetData['urlaubs_modus'])) {
                $this->sendJSON($LNG['fl_in_vacation_player']);
            }

            if (!empty($planetData)) {
                if (
                    $planetData['id'] != $USER['id'] && $this->configs->get()->adm_attack == 1
                    && $planetData['authattack'] > $USER['authlevel']
                ) {
                    $this->sendJSON($LNG['fl_admin_attack']);
                }
            }

            if (!empty($planetData)) {
                if ($planetData['destruyed'] != 0 && $targetPlanetType != 2) {
                    $this->sendJSON($LNG['fl_error_not_avalible']);
                }
            }

            if ($targetPlanetType == 2 && (empty($planetData) || ($planetData['der_metal'] + $planetData['der_crystal']) == 0
                && $planetData['tf_active'] == 0)) {
                $this->sendJSON($LNG['fl_error_empty_derbis']);
            }

            // $sql = 'SELECT (
            // 	(SELECT COUNT(*) FROM %%MULTI%% WHERE userID = :userID) +
            // 	(SELECT COUNT(*) FROM %%MULTI%% WHERE userID = :dataID)
            // ) as count;';

            // if (!empty($planetData)) {
            //     $multiCount = $db->selectSingle($sql, [
            //         ':userID' => $USER['id'],
            //         ':dataID' => $planetData['id'],
            //     ], 'count');
            // }

            // if (
            //     ENABLE_MULTIALERT && $USER['id'] != $planetData['id'] && $USER['authlevel'] != AUTH_ADM
            //     && $USER['user_lastip'] == $planetData['user_lastip'] && $multiCount != 2
            // ) {
            //     $this->sendJSON($LNG['fl_multi_alarm']);
            // }
        } else {
            if ($USER[$resource[124]] == 0) {
                $this->sendJSON($LNG['fl_expedition_tech_required']);
            }

            $activeExpedition = $this->fleetFunctions()->getCurrentFleets($USER['id'], 15, true);

            if ($activeExpedition >= $this->fleetFunctions()->getExpeditionLimit($USER)) {
                $this->sendJSON($LNG['fl_no_expedition_slot']);
            }
        }

        $this->sendJSON('OK');
    }
}
