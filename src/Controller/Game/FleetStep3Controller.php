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
use Exception;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\HTTP;
use App\Game\Core\Session;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\AdvancedStats\AdvancedStatsEnum;

/** Fleet dispatch, step 3: validates the prepared fleet and sends it. */
#[\App\Routing\GamePage('game.php', 'fleetStep3')]
#[\App\Routing\GamePage('game.php', 'fleetStep3', uniPrefix: true)]
class FleetStep3Controller extends AbstractGameController
{
    public static $requireModule = 'MODULE_FLEET_TABLE';

    public function initialize(): void
    {
        parent::initialize();
    }

    private function getActivePlanet($db, $planetId)
    {
        $sql    = "SELECT * FROM %%PLANETS%% WHERE `id` = :planetId FOR UPDATE;";
        $planet = $db->selectSingle($sql, [
            ':planetId' => $planetId,
        ]);

        return $planet;
    }

    public function show(): Response
    {
        $USER = & $this->state->USER;
        $PLANET = & $this->state->PLANET;
        $resource = & $this->state->resource;
        $LNG = & $this->state->LNG;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        if (IsVacationMode($USER)) {
            $this->fleetFunctions()->gotoFleetPage(0);
        }

        $db = $this->db;
        $db->startTransaction();
        // Serialise parallel dispatches of the same player, so the slot and expedition limits cannot be raced
        // from several planets/sessions (the user row is locked before the planet row, always in this order).
        $db->selectSingle('SELECT `id` FROM %%USERS%% WHERE `id` = :userId FOR UPDATE;', [':userId' => $USER['id']]);
        // reloading global $PLANET for database lock support.
        // not pretty, but not using the global dupes resources
        $pPlanet = $this->getActivePlanet($db, $PLANET['id']);

        $targetMission = $this->http()->_GP('mission', MISSION_TRANSPORT);
        $TransportMetal = max(0, round($this->http()->_GP('metal', 0.0)));
        $TransportCrystal = max(0, round($this->http()->_GP('crystal', 0.0)));
        $TransportDeuterium = max(0, round($this->http()->_GP('deuterium', 0.0)));
        $WantedResourceType = $this->http()->_GP('resEx', 0);
        $WantedResourceAmount = max(0, round($this->http()->_GP('exchange', 0.0)));
        $markettype = $this->http()->_GP('markettype', 0);
        $visibility = $this->http()->_GP('visibility', 0);
        $maxFlightTime = $this->http()->_GP('maxFlightTime', 0);
        $stayTime = $this->http()->_GP('staytime', 0);
        $token = $this->http()->_GP('token', '');

        $config = $this->configs->get();

        $session = $this->sessions()->load();
        $fleet = $session->fleet;

        if (!isset($fleet[$token])) {
            $this->fleetFunctions()->gotoFleetPage(1);
        }

        if ($fleet[$token]['time'] < $this->state->runtime->timestamp - 600) {
            unset($fleet[$token]);
            $session->fleet = $fleet;
            $session->save();
            $this->fleetFunctions()->gotoFleetPage(0);
        }

        $session = $this->sessions()->load();
        $fleet = $session->fleet;
        $formData = $fleet[$token];
        unset($fleet[$token]);
        $session->fleet = $fleet;
        $session->save();

        $distance = $formData['distance'];
        $targetGalaxy = $formData['targetGalaxy'];
        $targetSystem = $formData['targetSystem'];
        $targetPlanet = $formData['targetPlanet'];
        $targetType = $formData['targetType'];
        $fleetGroup = $formData['fleetGroup'];
        $fleetArray = $formData['fleet'];
        $fleetStorage = $formData['fleetRoom'];
        $fleetSpeed = $formData['fleetSpeed'];
        $ownPlanet = $formData['ownPlanet'];

        if ($ownPlanet != $PLANET['id']) {
            $this->printMessage($LNG['fl_own_planet_error'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetStep1',
            ]]);
        }

        if ($targetMission != MISSION_ACS) {
            $fleetGroup = 0;
        }

        if (
            $PLANET['galaxy'] == $targetGalaxy && $PLANET['system'] == $targetSystem
            && $PLANET['planet'] == $targetPlanet && $PLANET['planet_type'] == $targetType
        ) {
            $this->printMessage($LNG['fl_error_same_planet'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetStep1',
            ]]);
        }

        if (
            $targetGalaxy < 1 || $targetGalaxy > $config->max_galaxy
            || $targetSystem < 1 || $targetSystem > $config->max_system
            || $targetPlanet < 1 || $targetPlanet > ($config->max_planets + 3)
            || ($targetType !== 1 && $targetType !== 2 && $targetType !== 3)
        ) {
            $this->printMessage($LNG['fl_invalid_target'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetStep1',
            ]]);
        }

        if ($targetMission == MISSION_TRADE) {
            // Whitelist the trade parameters (the AJAX pre-check in step 2 can be skipped with a crafted request)
            $WantedResourceType = (int) $WantedResourceType;
            $markettype = (int) $markettype;
            if (!in_array($WantedResourceType, [1, 2, 3], true) || !in_array($markettype, [0, 1], true)) {
                $this->printMessage($LNG['fl_invalid_mission'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetStep2',
                ]]);
            }
        }

        // Transport and market type 0 have to contain resources
        if (
            ($targetMission == MISSION_TRANSPORT || ($targetMission == MISSION_TRADE && $markettype == 0))
            && $TransportMetal + $TransportCrystal + $TransportDeuterium < 1
        ) {
            $this->printMessage($LNG['fl_no_noresource'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetStep2',
            ]]);
        }
        // Market typ 1 cannot contain resources
        if (
            $targetMission == MISSION_TRADE && $markettype == 1
            && $TransportMetal + $TransportCrystal + $TransportDeuterium != 0
        ) {
            $this->printMessage($LNG['fl_resources'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetStep2',
            ]]);
        }

        if ($targetMission == MISSION_TRADE && $WantedResourceAmount < 1) {
            $this->printMessage($LNG['fl_no_noresource_exchange'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetStep2',
            ]]);
        }

        if ($targetMission == MISSION_TRADE && $WantedResourceAmount > pow(10, 50)) {
            $this->printMessage($LNG['fl_invalid_mission'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetStep2',
            ]]);
        }

        $ActualFleets = $this->fleetFunctions()->getCurrentFleets($USER['id']);

        if ($this->fleetFunctions()->getMaxFleetSlots($USER) <= $ActualFleets) {
            $this->printMessage($LNG['fl_no_slots'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetTable',
            ]]);
        }

        $ACSTime = 0;

        if (!empty($fleetGroup)) {
            $sql = "SELECT `ankunft`
                FROM %%USERS_TO_ACS%%
                INNER JOIN %%AKS%% ON `id` = `acsID`
                WHERE `acsID` = :acsID
                AND `userID` = :userID
                AND :maxFleets > (SELECT COUNT(*) FROM %%FLEETS%% WHERE `group` = :acsID);";
            $ACSTime = $db->selectSingle($sql, [
                ':acsID'        => $fleetGroup,
                ':userID'       => $USER['id'],
                ':maxFleets'    => $config->max_fleets_per_acs,
            ], 'ankunft');

            if (!empty($ACSTime)) {
                $sql = "SELECT `ankunft`
                    FROM %%USERS_TO_ACS%%
                    INNER JOIN %%AKS%% ON `id` = `acsID`
                    WHERE `acsID` = :acsID
                      AND `userID` = :userID
                      AND ( :maxParticipants > (SELECT COUNT(DISTINCT `owner`) FROM %%FLEETS%% WHERE `group` = :acsID)
                       OR 1 = (SELECT COUNT(DISTINCT `owner`) FROM %%FLEETS%% WHERE `group` = :acsID AND `owner` = :userID) );";
                $ACSTime = $db->selectSingle($sql, [
                    ':acsID'        => $fleetGroup,
                    ':userID' => $USER['id'],
                    ':maxParticipants' => $config->max_participants_per_acs,
                ], 'ankunft');
            }

            if (empty($ACSTime)) {
                $fleetGroup = 0;
                $targetMission = MISSION_ATTACK;
            }
        }

        if ($targetPlanet == $config->max_planets + 3) {
            $sql = "SELECT `id`, `id_owner`, `der_metal`, `der_crystal`, 0 as `destruyed`, 0 as `ally_deposit`
                FROM %%PIRATE_BASES%% 
                WHERE `universe` = :universe AND `galaxy` = :targetGalaxy AND `system` = :targetSystem AND `id_owner` = :userId;";
            $targetPlanetData = $db->selectSingle($sql, [
                ':universe' => $this->universe()->current(),
                ':targetGalaxy' => $targetGalaxy,
                ':targetSystem' => $targetSystem,
                ':userId' => $USER['id'],
            ]);
        } else {
            $sql = "SELECT `id`, `id_owner`, `der_metal`, `der_crystal`, `destruyed`, `ally_deposit`
                FROM %%PLANETS%%
                WHERE `universe` = :universe AND `galaxy` = :targetGalaxy
                  AND `system` = :targetSystem AND `planet` = :targetPlanet AND `planet_type` = :targetType;";
            $targetPlanetData = $db->selectSingle($sql, [
                ':universe' => $this->universe()->current(),
                ':targetGalaxy' => $targetGalaxy,
                ':targetSystem' => $targetSystem,
                ':targetPlanet' => $targetPlanet,
                ':targetType' => ($targetType == 2 ? 1 : $targetType),
            ]);
        }

        if ($targetMission == MISSION_COLONISATION) {
            if (!empty($targetPlanetData)) {
                $this->printMessage($LNG['fl_target_exists'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetStep1',
                ]]);
            }

            if ($targetType != 1) {
                $this->printMessage($LNG['fl_only_planets_colonizable'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetStep1',
                ]]);
            }
        }

        if ($targetMission == MISSION_COLONISATION || $targetMission == MISSION_EXPEDITION || $targetMission == MISSION_TRADE) {
            $targetPlanetData = ['id' => 0, 'id_owner' => 0, 'planettype' => 1];
        } else {
            if (!empty($targetPlanetData["destruyed"]) && $targetMission != MISSION_RECYCLING) {
                $this->printMessage($LNG['fl_no_target'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetStep1',
                ]]);
            }

            if (empty($targetPlanetData)) {
                $this->printMessage($LNG['fl_no_target'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetStep1',
                ]]);
            }
        }
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
            $activeExpedition = $this->fleetFunctions()->getCurrentFleets($USER['id'], MISSION_EXPEDITION, true);
            $maxExpedition = $this->fleetFunctions()->getExpeditionLimit($USER);

            if ($activeExpedition >= $maxExpedition) {
                $this->printMessage($LNG['fl_no_expedition_slot'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetTable',
                ]]);
            }
            if ($containsOnlyProbes) {
                $this->printMessage($LNG['fl_no_probe_expos'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetTable',
                ]]);
            }
        }

        // An ACS fleet must fly to the planet the ACS was created for
        if ($fleetGroup != 0) {
            $acsTarget = $db->selectSingle('SELECT `target` FROM %%AKS%% WHERE `id` = :acsID;', [
                ':acsID' => $fleetGroup,
            ], 'target');
            if ((int) $acsTarget !== (int) ($targetPlanetData['id'] ?? 0)) {
                $this->printMessage($LNG['fl_invalid_mission'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetStep2',
                ]]);
            }
        }

        $usedPlanet = isset($targetPlanetData['id_owner']);
        $myPlanet = $usedPlanet && $targetPlanetData['id_owner'] == $USER['id'];
        $targetPlayerData = [];

        if ($targetMission == MISSION_COLONISATION || $targetMission == MISSION_EXPEDITION || $targetMission == MISSION_TRADE) {
            $targetPlayerData = [
                'id'                    => 0,
                'onlinetime'            => $this->state->runtime->timestamp,
                'ally_id'               => 0,
                'urlaubs_modus'         => 0,
                'authattack'            => 0,
                'total_points'          => 0,
                'rebuild_protection'    => 0,
            ];
        } elseif ($myPlanet) {
            $targetPlayerData = $USER;
        } elseif (!empty($targetPlanetData['id_owner'])) {
            $sql = "SELECT user.*, stat.`total_points`
                FROM %%USERS%% as user
                LEFT JOIN %%STATPOINTS%% as stat ON stat.`id_owner` = user.`id` AND stat.`stat_type` = '1'
                WHERE user.`id` = :ownerID;";

            $targetPlayerData = $db->selectSingle($sql, [':ownerID'  => $targetPlanetData['id_owner']]);
        }

        if (empty($targetPlayerData)) {
            $this->printMessage($LNG['fl_empty_target'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetStep1',
            ]]);
        }

        $MisInfo = [];
        $MisInfo['galaxy'] = $targetGalaxy;
        $MisInfo['system'] = $targetSystem;
        $MisInfo['planet'] = $targetPlanet;
        $MisInfo['planettype'] = $targetType;
        $MisInfo['IsAKS'] = $fleetGroup;
        $MisInfo['Ship'] = $fleetArray;

        $availableMissions = $this->fleetFunctions()->getFleetMissions($USER, $MisInfo, $targetPlanetData);

        if (!in_array($targetMission, $availableMissions['MissionSelector'])) {
            $this->printMessage($LNG['fl_invalid_mission'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetStep2',
            ]]);
        }

        if ($targetMission != MISSION_RECYCLING && IsVacationMode($targetPlayerData)) {
            $this->printMessage($LNG['fl_target_exists'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetStep1',
            ]]);
        }

        if ($targetMission == MISSION_ATTACK || $targetMission == MISSION_ACS || $targetMission == MISSION_DESTRUCTION) {
            if (count($fleetArray) != 1 || !array_key_exists(210, $fleetArray)) {
                if ($this->fleetFunctions()->checkBash($targetPlanetData['id'], $fleetGroup)) {
                    $this->printMessage($LNG['fl_bash_protection'], [[
                        'label' => $LNG['sys_back'],
                        'url'   => 'game?page=fleetTable',
                    ]]);
                }
            }
        }

        if (
            $targetMission == MISSION_ATTACK || $targetMission == MISSION_ACS || $targetMission == MISSION_HOLD || $targetMission == MISSION_SPY
            || $targetMission == MISSION_DESTRUCTION
        ) {
            if ($this->configs->get()->adm_attack == 1 && $targetPlayerData['authattack'] > $USER['authlevel']) {
                $this->printMessage($LNG['fl_admin_attack'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetTable',
                ]]);
            }

            $sql = 'SELECT `total_points`
                FROM %%STATPOINTS%%
                WHERE `id_owner` = :userId AND `stat_type` = :statType';

            try {
                $USER += $this->db->selectSingle($sql, [
                    ':userId' => $USER['id'],
                    ':statType' => 1,
                ]) ?: ['total_points' => 0];
            } catch (Exception $exception) {
            }

            $IsNoobProtec = $this->playerUtil()->checkNoobProtection($USER, $targetPlayerData);

            if ($IsNoobProtec['NoobPlayer']) {
                $this->printMessage($LNG['fl_player_is_noob'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetTable',
                ]]);
            }

            if ($IsNoobProtec['StrongPlayer']) {
                if ($this->configs->get()->noob_protection_outlaw) {
                    $db->update('UPDATE %%USERS%% SET outlaw_time = :outlawTime WHERE id = :userId;', [
                        ':outlawTime' => $this->state->runtime->timestamp,
                        ':userId'     => $USER['id'],
                    ]);
                    $statsHandler->increment($USER['id'], AdvancedStatsEnum::OUTLAW_ACTIVATIONS);
                } else {
                    $this->printMessage($LNG['fl_player_is_strong'], [
                        [
                            'label' => $LNG['sys_back'],
                            'url'   => 'game?page=fleetTable',
                        ],
                    ]);
                }
            }
        }

        if ($targetMission == MISSION_HOLD || $targetMission == MISSION_TRANSFER) {
            if ($targetPlayerData['ally_id'] != $USER['ally_id'] || $USER['ally_id'] == 0) {
                $sql = "SELECT COUNT(*) as state FROM %%BUDDY%%
                    WHERE `id` NOT IN (SELECT `id` FROM %%BUDDY_REQUEST%% WHERE %%BUDDY_REQUEST%%.`id` = %%BUDDY%%.`id`) 
                      AND ((`owner` = :ownerID AND `sender` = :userID) OR (`owner` = :userID AND `sender` = :ownerID));";
                $buddy = $db->selectSingle($sql, [
                    ':ownerID'  => $targetPlayerData['id'],
                    ':userID'   => $USER['id'],
                ], 'state');

                if ($buddy == 0) {
                    $this->printMessage($LNG['fl_no_same_alliance'], [[
                        'label' => $LNG['sys_back'],
                        'url'   => 'game?page=fleetTable',
                    ]]);
                }
            }
        }

        if ($targetMission == MISSION_HOLD) {
            $sql = "SELECT COUNT(id) AS anz
                FROM %%FLEETS%%
                WHERE `end_id` = :planetID AND `mission` = :mission AND `status` IN (:stateOutward, :stateHold);";
            $fleetCount = $db->selectSingle($sql, [
                ':planetID' => $targetPlanetData['id'],
                ':mission'  => MISSION_HOLD,
                ':stateOutward' => FLEET_OUTWARD,
                ':stateHold' => FLEET_HOLD,
            ], "anz");

            $sql = "SELECT COUNT(DISTINCT `owner`) AS anz FROM %%FLEETS%%
                WHERE `end_id` = :planetID AND `mission` = :mission AND `status` IN (:stateOutward, :stateHold)
                  AND `owner` != :userID;";
            $playerCount = $db->selectSingle($sql, [
                ':planetID' => $targetPlanetData['id'],
                ':mission'  => MISSION_HOLD,
                ':stateOutward' => FLEET_OUTWARD,
                ':stateHold' => FLEET_HOLD,
                ':userID' => $USER['id'],
            ], "anz");

            if (($fleetCount + 1) >= $config->max_fleets_per_acs) {
                $this->printMessage($LNG['fl_hold_max_fleets'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetTable',
                ]]);
            } elseif (($playerCount + 1) >= $config->max_participants_per_acs) {
                $this->printMessage($LNG['fl_hold_max_user'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetTable',
                ]]);
            }
        }

        $fleetMaxSpeed = $this->fleetFunctions()->getFleetMaxSpeed($fleetArray, $USER);
        $SpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $duration = $this->fleetFunctions()->getMissionDuration($fleetSpeed, $fleetMaxSpeed, $distance, $SpeedFactor, $USER);
        $consumption = $this->fleetFunctions()->getFleetConsumption($fleetArray, $duration, $distance, $USER, $SpeedFactor);

        if ($PLANET[$resource[903]] < $consumption) {
            $this->printMessage($LNG['fl_not_enough_deuterium'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetTable',
            ]]);
        }

        $StayDuration = 0;

        if ($targetMission == MISSION_HOLD || $targetMission == MISSION_EXPEDITION || $targetMission == MISSION_TRADE) {
            if (!isset($availableMissions['StayBlock'][$stayTime])) {
                $this->printMessage($LNG['fl_hold_time_not_exists'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetTable',
                ]]);
            }

            $StayDuration = round($availableMissions['StayBlock'][$stayTime] * 3600, 0);
        }

        $fleetStorage -= $consumption;

        $fleetResource = [
            901 => min($TransportMetal, floor($PLANET[$resource[901]])),
            902 => min($TransportCrystal, floor($PLANET[$resource[902]])),
            903 => min($TransportDeuterium, floor($PLANET[$resource[903]] - $consumption)),
        ];

        $StorageNeeded = max(array_sum($fleetResource), $WantedResourceAmount);

        if ($StorageNeeded > $fleetStorage) {
            $this->printMessage($LNG['fl_not_enough_space'], [[
                'label' => $LNG['sys_back'],
                'url'   => 'game?page=fleetTable',
            ]]);
        }


        if ($targetMission == MISSION_TRANSFER) {
            $attack = $USER[$resource[109]] * 10;
            $shield = $USER[$resource[110]] * 10;
            $defensive = $USER[$resource[111]] * 10;

            $attack_targ = $targetPlayerData[$resource[109]] * 10;
            $shield_targ = $targetPlayerData[$resource[110]] * 10;
            $defensive_targ = $targetPlayerData[$resource[111]] * 10;

            if ($attack < $attack_targ || $defensive < $defensive_targ || $shield < $shield_targ) {
                $this->printMessage($LNG['fl_stronger_techs'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetTable',
                ]]);
            }
        }

        $PLANET[$resource[901]] -= $fleetResource[901];
        $PLANET[$resource[902]] -= $fleetResource[902];
        $PLANET[$resource[903]] -= $fleetResource[903] + $consumption;

        $fleetStartTime = floor($duration) + $this->state->runtime->timestamp;
        $timeDifference = round(max(0, $fleetStartTime - $ACSTime));

        if ($fleetGroup != 0) {
            if ($timeDifference != 0) {
                // A joiner may delay the whole ACS by at most 30% of its remaining time
                if ($timeDifference > max(0, $ACSTime - $this->state->runtime->timestamp) * 0.3) {
                    $this->printMessage($LNG['fl_invalid_mission'], [[
                        'label' => $LNG['sys_back'],
                        'url'   => 'game?page=fleetTable',
                    ]]);
                }
                $this->fleetFunctions()->setACSTime($timeDifference, $fleetGroup);
            } else {
                $fleetStartTime = $ACSTime;
            }
        }

        $fleetStayTime = floor($fleetStartTime + $StayDuration);
        $fleetEndTime = floor($fleetStayTime + $duration);

        $id = $this->fleetFunctions()->sendFleet(
            $fleetArray,
            $targetMission,
            $USER['id'],
            $PLANET['id'],
            $PLANET['galaxy'],
            $PLANET['system'],
            $PLANET['planet'],
            $PLANET['planet_type'],
            $targetPlanetData['id_owner'],
            $targetPlanetData['id'],
            $targetGalaxy,
            $targetSystem,
            $targetPlanet,
            $targetType,
            $fleetResource,
            $fleetStartTime,
            $fleetStayTime,
            $fleetEndTime,
            $fleetGroup,
            0, // missileTarget
            0, // noReturn
            $consumption,
        );

        if ($targetMission == MISSION_TRADE) {
            if ($fleetResource[901] > 0) {
                $resourceType = 1;
                $resourceAmount = $fleetResource[901];
            } elseif ($fleetResource[902] > 0) {
                $resourceType = 2;
                $resourceAmount = $fleetResource[902];
            } elseif ($fleetResource[903] > 0) {
                $resourceType = 3;
                $resourceAmount = $fleetResource[903];
            } else {
                $resourceType = 0;
                $resourceAmount = 0;
            }

            $sql = 'INSERT INTO %%TRADES%% SET
				`transaction_type`      = :transaction,
                `seller_fleet_id`       = :sellerFleet,
				`filter_visibility`     = :visibility,
				`filter_flighttime`     = :flightTime,
				`ex_resource_type`      = :exResType,
				`ex_resource_amount`    = :exResAmount,
                `resource_type`         = :resType,
                `resource_amount`       = :resAmount,
                `marketplace_galaxy`    = :galaxy,
                `marketplace_system`    = :system;';

            $db->insert($sql, [
                ':transaction'          => $markettype,
                ':sellerFleet'          => $id,
                ':exResType'            => $WantedResourceType,
                ':exResAmount'          => $WantedResourceAmount,
                ':flightTime'           => $maxFlightTime * 3600,
                ':visibility'           => $visibility,
                ':resType'              => $resourceType,
                ':resAmount'            => $resourceAmount,
                ':galaxy'               => $targetGalaxy,
                ':system'               => $targetSystem,
            ]);
        }

        $db->commit();

        foreach ($fleetArray as $Ship => $Count) {
            $fleetList[$LNG['tech'][$Ship]] = $Count;
        }

        $LNG->includeData(['FLEET']);

        $keySender = 'type_planet_short_' . $PLANET['planet_type'];
        $startTypeLetter = ' (' . $LNG[$keySender] . ')';
        $targetType = $targetMission == MISSION_RECYCLING ? 2 : $targetType;
        $keyTarget = 'type_planet_short_' . $targetType;
        $targetTypeLetter = ' (' . $LNG[$keyTarget] . ')';

        $this->assign(['goto' => 'game?page=fleetTable', 'gotoinsec' => 3]);
        $this->assign([
            'targetMission'     => $targetMission,
            'distance'          => $distance,
            'consumption'       => $consumption,
            'from'              => $PLANET['galaxy'] . ":" . $PLANET['system'] . ":" . $PLANET['planet'] . $startTypeLetter,
            'destination'       => $targetGalaxy . ":" . $targetSystem . ":" . $targetPlanet . $targetTypeLetter,
            'fleetStartTime'    => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $fleetStartTime, $USER['timezone']),
            'fleetEndTime'      => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $fleetEndTime, $USER['timezone']),
            'MaxFleetSpeed'     => $fleetMaxSpeed,
            'FleetList'         => $fleetArray,
        ]);

        return $this->render('game/page.fleetStep3.default.html.twig');
    }
}
