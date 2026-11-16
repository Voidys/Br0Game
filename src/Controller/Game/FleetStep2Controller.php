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

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\HTTP;
use App\Game\Core\MarketManager;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Session;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/** Fleet dispatch, step 2: mission, cargo and trade options (modes: show, checkResources). */
#[\App\Routing\GamePage('game.php', 'fleetStep2')]
#[\App\Routing\GamePage('game.php', 'fleetStep2', uniPrefix: true)]
class FleetStep2Controller extends AbstractGameController
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
        $LNG = $this->state->LNG;
        $resource = $this->state->resource;
        $this->addScript('flotten.js');
        $config = $this->configs->get();

        $targetGalaxy = $this->http()->_GP('galaxy', 0);
        $targetSystem = $this->http()->_GP('system', 0);
        $targetPlanet = $this->http()->_GP('planet', 0);
        $targetType = $this->http()->_GP('type', 0);
        $targetMission = $this->http()->_GP('target_mission', -1);
        $fleetSpeed = $this->http()->_GP('speed', 0);
        $fleetGroupPublicId = $this->http()->_GP('group', '');
        $fleetGroup = 0;
        $token = $this->http()->_GP('token', '');

        $session = $this->sessions()->load();
        $fleet = $session->fleet;
        if (!isset($fleet[$token])) {
            $this->fleetFunctions()->gotoFleetPage();
        }


        $fleetArray = $fleet[$token]['fleet'];

        $db = $this->db;
        if ($targetPlanet == $config->max_planets + 3) {
            $sql = "SELECT `id`, `id_owner`, `der_metal`, `der_crystal`, 0 as `tf_active`
                FROM %%PIRATE_BASES%% 
                WHERE `universe` = :universe AND `galaxy` = :targetGalaxy AND `system` = :targetSystem AND `id_owner` = :userId;";
            $targetPlanetData = $db->selectSingle($sql, [
                ':universe' => $this->universe()->current(),
                ':targetGalaxy' => $targetGalaxy,
                ':targetSystem' => $targetSystem,
                ':userId' => $USER['id'],
            ]);
        } else {
            if (!empty($fleetGroupPublicId)) {
                $sql = "SELECT acs.`id`
                    FROM %%USERS_TO_ACS%% usersToAcs
                    INNER JOIN %%AKS%% acs ON usersToAcs.acsID = acs.`id`
                    WHERE usersToAcs.`userID` = :userID AND acs.`public_id` = :publicId;";
                $fleetGroup = (int) $db->selectSingle($sql, [
                    ':userID' => $USER['id'],
                    ':publicId' => $fleetGroupPublicId,
                ], 'id');
            }

            $sql = "SELECT `id`, `id_owner`, `der_metal`, `der_crystal`, `tf_active`
                FROM %%PLANETS%%
                WHERE `universe` = :universe AND `galaxy` = :targetGalaxy AND
                `system` = :targetSystem AND `planet` = :targetPlanet AND `planet_type` = :planetType;";
            // A moon target needs the moon row; debris fields hang off the planet (type 1)
            $targetPlanetData = $db->selectSingle($sql, [
                ':planetType' => $targetType == 3 ? 3 : 1,
                ':universe' => $this->universe()->current(),
                ':targetGalaxy' => $targetGalaxy,
                ':targetSystem' => $targetSystem,
                ':targetPlanet' => $targetPlanet,
            ]);
        }
        if (empty($targetPlanetData)) {
            $targetPlanetData = [];
        }

        if ($targetType == 2 && !empty($targetPlanetData) && ($targetPlanetData['der_metal'] + $targetPlanetData['der_crystal']) == 0
            && $targetPlanetData['tf_active'] == 0) {
            $this->printMessage($LNG['fl_error_empty_derbis'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetTable',
            ]]);
        }

        $MisInfo = [];
        $MisInfo['galaxy'] = $targetGalaxy;
        $MisInfo['system'] = $targetSystem;
        $MisInfo['planet'] = $targetPlanet;
        $MisInfo['planettype'] = $targetType;
        $MisInfo['IsAKS'] = $fleetGroup;
        $MisInfo['Ship'] = $fleetArray;

        $MissionOutput = $this->fleetFunctions()->getFleetMissions($USER, $MisInfo, $targetPlanetData);

        if (empty($MissionOutput['MissionSelector'])) {
            $this->printMessage($LNG['fl_empty_target'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetTable',
            ]]);
        }

        if ($targetMission == -1) {
            $sql = "SELECT `prioMission1` as '1', `prioMission2` as '2', `prioMission3` as '3', `prioMission4` as '4', `prioMission5` as '5',
                    `prioMission6` as '6', `prioMission7` as '7', `prioMission8` as '8', `prioMission9` as '9', `prioMission17` as '17'
                FROM %%USERS_SETTINGS%%
                WHERE `user_id` = :userID ;";
            $missionprios = $db->selectSingle($sql, [
                ':userID'   => $USER['id'],
            ]);
            $bestmission = 999;
            foreach ($MissionOutput['MissionSelector'] as $mission) {
                if (array_key_exists($mission, $missionprios)) {
                    $score = $missionprios[$mission];
                    if ($score < $bestmission) {
                        $targetMission = $mission;
                        $bestmission = $score;
                    }
                }
            }
        }
        $GameSpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $userModel = $this->models()->user($USER);
        $MaxFleetSpeed = $this->fleetFunctions()->fleetMaxSpeedFor($fleetArray, $userModel);
        $distance = $this->fleetFunctions()->getTargetDistance(
            [$PLANET['galaxy'], $PLANET['system'], $PLANET['planet']],
            [$targetGalaxy, $targetSystem, $targetPlanet],
        );
        $duration = $this->fleetFunctions()->missionDurationFor($fleetSpeed, $MaxFleetSpeed, $distance, $GameSpeedFactor, $userModel);
        $consumption = $this->fleetFunctions()->fleetConsumptionFor($fleetArray, $duration, $distance, $userModel, $GameSpeedFactor);

        if ($consumption > $PLANET['deuterium']) {
            $this->printMessage($LNG['fl_not_enough_deuterium'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetTable',
            ]]);
        }

        if (!$this->fleetFunctions()->checkUserSpeed($fleetSpeed)) {
            $this->fleetFunctions()->gotoFleetPage(0);
        }

        $fleet[$token]['speed'] = $MaxFleetSpeed;
        $fleet[$token]['distance'] = $distance;
        $fleet[$token]['targetGalaxy'] = $targetGalaxy;
        $fleet[$token]['targetSystem'] = $targetSystem;
        $fleet[$token]['targetPlanet'] = $targetPlanet;
        $fleet[$token]['targetType'] = $targetType;
        $fleet[$token]['fleetGroup'] = $fleetGroup;
        $fleet[$token]['fleetSpeed'] = $fleetSpeed;
        $fleet[$token]['ownPlanet'] = $PLANET['id'];
        $session->fleet = $fleet;
        $session->save();

        if ($targetMission == 0) {
            if (count($MissionOutput['MissionSelector']) == 1) {
                $targetMission = $MissionOutput['MissionSelector'][0];
            } elseif (!empty($targetPlanetData) && $targetPlanetData['id_owner'] == $USER['id']) {
                $targetMission = MISSION_TRANSPORT;
            } else {
                if (count($fleetArray) == 1 && isset($fleetArray[SHIP_PROBE])) {
                    $targetMission = MISSION_SPY;
                } elseif (!empty($targetPlanetData)) {
                    $sql = "SELECT a.`id`, a.`ally_tag`, a.`ally_name`, d.`level`, d.`owner_2`
                        FROM %%USERS%% u
                        JOIN %%ALLIANCE%% a ON a.`id` = u.`ally_id`
                        JOIN %%DIPLO%% d ON ((d.`owner_1` = u.`ally_id` AND d.`owner_2` = :ally_id)
                            OR (d.`owner_2` = u.`ally_id` AND d.`owner_1` = :ally_id))
                            AND d.`accept` = 1
                        WHERE u.`universe` = :universe AND u.`id` = :targetPlayerId AND d.`level` IN (1,2,4,6);";
                    $targetAllianceData = $db->selectSingle($sql, [
                        ':universe' => $this->universe()->current(),
                        ':targetPlayerId' => $targetPlanetData['id_owner'],
                        ':ally_id' => $USER['ally_id'],
                    ]);

                    $sql = "SELECT count(*) as anz FROM %%BUDDY%%
                        WHERE (`sender` = :id AND `owner` = :targetPlayerId)
                           OR (`sender` = :targetPlayerId AND `owner` = :id);";
                    $buddy = $db->selectSingle($sql, [
                        ':id' => $USER['id'],
                        ':targetPlayerId' => $targetPlanetData['id_owner'],
                    ]);

                    if ($targetAllianceData || $buddy['anz'] > 0) {
                        $targetMission = MISSION_TRANSPORT;
                    } else {
                        $targetMission = MISSION_ATTACK;
                    }
                } else {
                    $targetMission = MISSION_ATTACK;
                }
            }
        }

        if (!empty($fleetGroup) && $targetMission == MISSION_ATTACK) {
            $targetMission  = MISSION_ACS;
        }

        $questionList = [];
        $question = '';

        if (!empty($targetPlanetData) && $targetPlanetData['id_owner'] != $USER['id']) {
            $sql = "SELECT a.`id`, a.`ally_tag`, a.`ally_name`, d.`level`, d.`owner_2`
                FROM %%USERS%% u
                JOIN %%ALLIANCE%% a ON a.`id` = u.`ally_id`
                JOIN %%DIPLO%% d ON ((d.`owner_1` = u.`ally_id` AND d.`owner_2` = :ally_id)
                    OR (d.`owner_2` = u.`ally_id` AND d.`owner_1` = :ally_id))
                    AND d.`accept` = 1
                WHERE u.`universe` = :universe AND u.`id` = :targetPlayerId AND d.`level` IN (1,2,4,6);";
            $targetAllianceData = $db->selectSingle($sql, [
                ':universe' => $this->universe()->current(),
                ':targetPlayerId' => $targetPlanetData['id_owner'],
                ':ally_id' => $USER['ally_id'],
            ]);

            $sql = "SELECT count(*) as anz FROM %%BUDDY%%
                WHERE (`sender` = :id AND `owner` = :targetPlayerId)
                   OR (`sender` = :targetPlayerId AND `owner` = :id);";
            $buddy = $db->selectSingle($sql, [
                ':id' => $USER['id'],
                ':targetPlayerId' => $targetPlanetData['id_owner'],
            ]);
            $config = $this->configs->get();

            if ($config->noob_protection_outlaw) {

                $targetPlayerData = $this->playerUtil()->getPlayerById($targetPlanetData['id_owner']);
                $IsNoobProtec = $this->playerUtil()->checkNoobProtectionFor($this->models()->user($USER), $this->models()->user($targetPlayerData));

                if ($IsNoobProtec['StrongPlayer']) {
                    $question = $LNG->format('fl_attack_confirm_outlaw',
                        $targetPlayerData['username'],
                    );

                    $questionHold = $LNG->format('fl_hold_confirm_outlaw',
                        $targetPlayerData['username'],
                    );

                    $questionSpy = $LNG->format('fl_spy_confirm_outlaw',
                        $targetPlayerData['username'],
                    );
                    $questionList[MISSION_HOLD] = $questionHold;
                    $questionList[MISSION_SPY] = $questionSpy;
                }
            } elseif ($targetAllianceData) {
                $question = $LNG->format('fl_attack_confirm_diplo',
                    (($targetAllianceData['owner_2'] == $targetAllianceData['id'] && $targetAllianceData['level'] == 1) ? $LNG['al_diplo_level'][0] : $LNG['al_diplo_level'][$targetAllianceData['level']]),
                    '[' . $targetAllianceData['ally_tag'] . '] ' . $targetAllianceData['ally_name'],
                );
            } elseif ($buddy['anz'] > 0) {
                $question = $LNG['fl_attack_confirm_buddy'];
            }

            $questionList[MISSION_ATTACK] = $question;
            $questionList[MISSION_ACS] = $question;
            $questionList[MISSION_DESTRUCTION] = $question;

        }

        if (empty($targetPlanetData) && $USER['colonyWarn'] == 1) {
            $sql = "SELECT count(*) as anz 
                FROM %%PLANETS%% 
                WHERE `id_owner` = :id 
                  AND `planet_type` = '1' 
                  AND `galaxy` = :galaxy 
                  AND `system` = :system
                  AND `destruyed` = 0;";

            $coloniesInSys = $db->selectSingle($sql, [
                ':id' => $USER['id'],
                ':galaxy' => $targetGalaxy,
                ':system' => $targetSystem,
            ]);
            if ($coloniesInSys['anz'] >= 2) {
                $questionList[MISSION_COLONISATION] = $LNG['fl_colonize_confirm'];
            }
        }

        $fleetData  = [
            'fleetroom'     => floatToString($fleet[$token]['fleetRoom']),
            'consumption'   => floatToString($consumption),
        ];

        $tradeMarket = null;
        if (!empty($MissionOutput['Exchange'])) {
            $pMarket = new MarketManager($this->db, $this->configs, $this->state);
            $deutRelative = $pMarket->getDeutRelativeRatios();
            $tradeMarket = [
                'rates' => [
                    'metal' => sprintf('%.12F', $deutRelative['metal']),
                    'crystal' => sprintf('%.12F', $deutRelative['crystal']),
                    'deuterium' => sprintf('%.12F', $deutRelative['deuterium']),
                ],
                'fleetDeut' => $pMarket->getFleetValue($fleetArray),
                'decimals' => MarketManager::REFERENCE_DECIMALS,
            ];
        }

        $this->assign([
            'questionList'      => $questionList,
            'fleetdata'         => $fleetData,
            'consumption'       => floatToString($consumption),
            'mission'           => $targetMission,
            'galaxy'            => $PLANET['galaxy'],
            'system'            => $PLANET['system'],
            'planet'            => $PLANET['planet'],
            'type'              => $PLANET['planet_type'],
            'MissionSelector'   => $MissionOutput['MissionSelector'],
            'StaySelector'      => $MissionOutput['StayBlock'],
            'Exchange'          => $MissionOutput['Exchange'],
            'tradeMarket'       => $tradeMarket,
            'fl_continue'       => $LNG['fl_continue'],
            'token'             => $token,
            'duration'          => $duration,
            'predefinedRes'     => $fleet[$token]['predefinedResources'],
        ]);

        $containsOnlyProbes = true;
        foreach ($fleetArray as $Ship => $Count) {
            if ($Count > $PLANET[$resource[$Ship]]) {
                $this->printMessage($LNG['fl_not_all_ship_avalible'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetTable',
                ]]);
            }
            if ($Ship != SHIP_PROBE) {
                $containsOnlyProbes = false;
            }
        }
        if ($targetMission == MISSION_EXPEDITION) {
            if ($containsOnlyProbes) {
                $this->printMessage($LNG['fl_no_probe_expos'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetTable',
                ]]);
            }
        }


        return $this->render('game/page.fleetStep2.default.html.twig');
    }

    public function checkResources(): void
    {
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;

        $targetMission = $this->http()->_GP('mission', MISSION_TRANSPORT);
        $TransportMetal = max(0, round($this->http()->_GP('metal', 0.0)));
        $TransportCrystal = max(0, round($this->http()->_GP('crystal', 0.0)));
        $TransportDeuterium = max(0, round($this->http()->_GP('deuterium', 0.0)));
        $WantedResourceType = $this->http()->_GP('resEx', 0);
        $WantedResourceAmount = max(0, round($this->http()->_GP('exchange', 0.0)));
        $markettype = $this->http()->_GP('markettype', 0); //0 = resources, 1 = ships

        if ($PLANET['metal'] < $TransportMetal) {
            $this->sendJSON($LNG->format('fl_not_enough', $LNG['tech'][RESOURCE_METAL]));
        }
        if ($PLANET['crystal'] < $TransportCrystal) {
            $this->sendJSON($LNG->format('fl_not_enough', $LNG['tech'][RESOURCE_CRYSTAL]));
        }

        $token = $this->http()->_GP('token', '');
        $session = $this->sessions()->load();
        $fleet = $session->fleet;
        if (!isset($fleet[$token]) || !isset($fleet[$token]['fleet']) || !isset($fleet[$token]['distance'])
            || !isset($fleet[$token]['fleetSpeed']) || !isset($fleet[$token]['fleetRoom'])) {
            $this->sendJSON(['message' => $LNG['fl_error'], 'error' => true]);
        }
        $formData = $fleet[$token];
        $fleetArray = $formData['fleet'];
        $distance = $formData['distance'];
        $fleetSpeed = $formData['fleetSpeed'];
        $fleetStorage = $formData['fleetRoom'];

        $GameSpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $userModel = $this->models()->user($USER);
        $MaxFleetSpeed = $this->fleetFunctions()->fleetMaxSpeedFor($fleetArray, $userModel);
        $duration = $this->fleetFunctions()->missionDurationFor($fleetSpeed, $MaxFleetSpeed, $distance, $GameSpeedFactor, $userModel);
        $consumption = $this->fleetFunctions()->fleetConsumptionFor($fleetArray, $duration, $distance, $userModel, $GameSpeedFactor);

        if ($PLANET['deuterium'] < $TransportDeuterium + $consumption) {
            $this->sendJSON($LNG->format('fl_not_enough', $LNG['tech'][RESOURCE_DEUT]));
        }

        if ($fleetStorage < $TransportMetal + $TransportCrystal + $TransportDeuterium + $consumption || $fleetStorage < $WantedResourceAmount) {
            $this->sendJSON($LNG['fl_not_enough_space']);
        }

        if ($targetMission == MISSION_TRADE) {
            $anz = 0;
            $anz += $TransportMetal > 0 ? 1 : 0;
            $anz += $TransportCrystal > 0 ? 1 : 0;
            $anz += $TransportDeuterium > 0 ? 1 : 0;

            if ($anz == 0 && $markettype == 0) {
                $this->sendJSON($LNG['fl_trade_no_resource_loaded']);
            } elseif ($anz > 1) {
                $this->sendJSON($LNG['fl_trade_to_many_resources_loaded']);
            }

            if ($TransportMetal > 0 && $WantedResourceType == 1) {
                $this->sendJSON($LNG->format('fl_trade_same_resource', $LNG['tech'][RESOURCE_METAL], $LNG['tech'][RESOURCE_METAL]));
            } elseif ($TransportCrystal > 0 && $WantedResourceType == 2) {
                $this->sendJSON($LNG->format('fl_trade_same_resource', $LNG['tech'][RESOURCE_CRYSTAL], $LNG['tech'][RESOURCE_CRYSTAL]));
            } elseif ($TransportDeuterium > 0 && $WantedResourceType == 3) {
                $this->sendJSON($LNG->format('fl_trade_same_resource', $LNG['tech'][RESOURCE_DEUT], $LNG['tech'][RESOURCE_DEUT]));
            }
        }

        $this->sendJSON('OK');
    }
}
