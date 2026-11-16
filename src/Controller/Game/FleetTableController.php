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
use App\Game\Core\BuildFunctions;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Fleet movements: flying fleets, fleet selection for dispatch, recall and alliance combat groups (modes: show,
 * createACS, loadACS).
 */
#[\App\Routing\GamePage('game.php', 'fleetTable')]
#[\App\Routing\GamePage('game.php', 'fleetTable', uniPrefix: true)]
class FleetTableController extends AbstractGameController
{
    public static $requireModule = 'MODULE_FLEET_TABLE';

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Creates an alliance combat group (AKS) led by the player's outgoing attack fleet $fleetID: inserts the group with a random name (`AG<number>`), a
     * public id, the fleet's arrival time and target, adds the player as first participant and sets `group` on the fleet and its LOG_FLEETS row.
     *
     * @param array{arrival_time: int, end_id: int} $fleetData row of the fleet
     * @return array{name: string, id: int, publicID: string}
     */
    public function createACS($fleetID, $fleetData)
    {
        $USER = $this->state->USER;

        $rand = random_int(100000, 999999999);
        $acsName = 'AG' . $rand;
        $acsCreator = $USER['id'];

        $db = $this->db;
        $publicId = bin2hex(random_bytes(16));

        $sql = "INSERT INTO %%AKS%% SET public_id = :publicID, name = :acsName, ankunft = :time, target = :target;";
        $db->insert($sql, [
            ':publicID' => $publicId,
            ':acsName' => $acsName,
            ':time' => $fleetData['arrival_time'],
            ':target' => $fleetData['end_id'],
        ]);

        $acsID = $db->lastInsertId();

        $sql = "INSERT INTO %%USERS_TO_ACS%% SET acsID = :acsID, userID = :userID;";
        $db->insert($sql, [
            ':acsID' => $acsID,
            ':userID' => $acsCreator,
        ]);

        $sql = "UPDATE %%FLEETS%% SET `group` = :acsID WHERE id = :fleetID AND owner = :userID;";
        $db->update($sql, [
            ':acsID' => $acsID,
            ':fleetID' => $fleetID,
            ':userID' => $USER['id'],
        ]);

        $sql = "UPDATE %%LOG_FLEETS%% SET `group` = :acsID WHERE id = :fleetID AND owner = :userID;";
        $db->update($sql, [
            ':acsID' => $acsID,
            ':fleetID' => $fleetID,
            ':userID' => $USER['id'],
        ]);

        return [
            'name' => $acsName,
            'id' => $acsID,
            'publicID' => $publicId,
        ];
    }

    /**
     * The combat group of $fleetData['group'] (id, public_id, name) if the player takes part in it, otherwise empty.
     */
    public function loadACS($fleetData)
    {
        $USER = $this->state->USER;

        $db = $this->db;
        $sql = "SELECT `id`, `public_id`, `name` FROM %%USERS_TO_ACS%% 
            INNER JOIN %%AKS%% ON `acsID` = `id`
            WHERE `userID` = :userID AND `acsID` = :acsID;";
        $acsResult = $db->selectSingle($sql, [
            ':userID' => $USER['id'],
            ':acsID' => $fleetData['group'],
        ]);

        return $acsResult;
    }

    /**
     * Combat group dialog for the player's fleet $fleetID (action=acs). Returns [] unless the fleet is outgoing and has not arrived yet, and is either
     * already in a group or an attack fleet (which then gets a new group through createACS()). Request acsName renames the group (valid names only) and
     * ends the request with JSON `false`; username invites a player of the universe unless unknown, already invited or blocking messages (insert into
     * USERS_TO_ACS and an invitation message in the invitee's language). Returns the invited users, group name, leading fleet id and a status message.
     */
    public function getACSPageData($fleetID)
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $db = $this->db;

        $sql = "SELECT `arrival_time`, `end_id`, `group`, `status`, `mission`
            FROM %%FLEETS%%
            WHERE `id` = :fleetID AND `owner` = :userID;";
        $fleetData = $db->selectSingle($sql, [
            ':fleetID' => $fleetID,
            ':userID' => $USER['id'],
        ]);

        if ($db->rowCount() != 1) {
            return [];
        }

        if ($fleetData['status'] == FLEET_RETURN || $fleetData['arrival_time'] <= $this->state->runtime->timestamp) {
            return [];
        }

        // Only outgoing attack fleets can lead an ACS
        if ($fleetData['group'] == 0 && ($fleetData['mission'] != MISSION_ATTACK || $fleetData['status'] != FLEET_OUTWARD)) {
            return [];
        }

        if ($fleetData['group'] == 0) {
            $acsData = $this->createACS($fleetID, $fleetData);
        } else {
            $acsData = $this->loadACS($fleetData);
        }

        if (empty($acsData)) {
            return [];
        }

        $acsName = $this->http()->_GP('acsName', '', UTF8_SUPPORT);
        if (!empty($acsName)) {
            if (!$this->playerUtil()->isNameValid($acsName)) {
                $this->sendJSON($LNG['fl_acs_newname_alphanum']);
            }

            $sql = "UPDATE %%AKS%% SET `name` = :acsName WHERE `id` = :acsID;";
            $db->update($sql, [
                ':acsName' => $acsName,
                ':acsID' => $acsData['id'],
            ]);
            $this->sendJSON(false);
        }

        $invitedUsers = [];

        $sql = "SELECT `id`, `username` FROM %%USERS_TO_ACS%% 
            INNER JOIN %%USERS%% ON `userID` = `id` 
            WHERE `acsID` = :acsID;";
        $userResult = $db->select($sql, [':acsID' => $acsData['id']]);

        foreach ($userResult as $userRow) {
            $invitedUsers[$userRow['id']] = $userRow['username'];
        }

        $newUser = $this->http()->_GP('username', '', UTF8_SUPPORT);
        $statusMessage = "";
        if (!empty($newUser)) {
            $sql = "SELECT `id` FROM %%USERS%% WHERE `universe` = :universe AND `username` = :username;";
            $newUserID = $db->selectSingle($sql, [
                ':universe' => $this->universe()->current(),
                ':username' => $newUser,
            ], 'id');
            $blocked    = $this->playerUtil()->get_block_status($USER['id'], $newUserID);
            $blockedInverted = $this->playerUtil()->get_block_status($newUserID, $USER['id']);

            if (empty($newUserID)) {
                $statusMessage = $LNG['fl_player'] . " " . $newUser . " " . $LNG['fl_dont_exist'];
            } elseif (isset($invitedUsers[$newUserID])) {
                $statusMessage = $LNG['fl_player'] . " " . $newUser . " " . $LNG['fl_already_invited'];
            } elseif ($blocked['block_dm'] == 1 || $blockedInverted['block_dm'] == 1) {
                $statusMessage = $LNG['fl_player'] . " " . $newUser . " " . $LNG['fl_blocked'];
            } else {
                $statusMessage = $LNG['fl_player'] . " " . $newUser . " " . $LNG['fl_add_to_attack'];

                $sql = "INSERT INTO %%USERS_TO_ACS%% SET `acsID` = :acsID, `userID` = :newUserID;";
                $db->insert($sql, [
                    ':acsID' => $acsData['id'],
                    ':newUserID' => $newUserID,
                ]);

                $invitedUsers[$newUserID] = $newUser;

                // get target player language while sending ACS invite instead of attack owner.
                $GetTargetLang = $this->playerUtil()->getLanguage(null, $newUserID);
                $inviteTitle = $GetTargetLang['fl_acs_invitation_title'];
                $inviteMessage = $GetTargetLang['fl_player'] . $USER['username']
                    . $GetTargetLang['fl_acs_invitation_message'];
                $this->playerUtil()->sendMessage(
                    $newUserID,
                    $USER['id'],
                    $USER['username'],
                    MESSAGE_TYPE_USER,
                    $inviteTitle,
                    $inviteMessage,
                    $this->state->runtime->timestamp,
                );
            }
        }

        return [
            'invitedUsers' => $invitedUsers,
            'acsName' => $acsData['name'],
            'mainFleetID' => $fleetID,
            'statusMessage' => $statusMessage,
        ];
    }

    /**
     * Fleet movements page. Request: fleetID with action = sendfleetback (recall the fleet) or acs (combat group dialog, see getACSPageData; both
     * ignored in vacation mode); galaxy, system, planet, planettype and target_mission are passed on as preselection for the dispatch form. Lists the
     * player's fleets (missiles excluded) with the next event time, route, cargo and ships, the fleet and expedition slots in use, the ships on the
     * current planet with their speed, the planet count, the research bonuses and the "cargo calculator" (resources and needed small/large cargos for the
     * current planet and for the element each planet watches).
     */
    public function show(): Response
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $reslist = $this->state->reslist;
        $resource = $this->state->resource;
        $LNG = $this->state->LNG;
        $LNG->includeData(['FLEET']);
        $pricelist = $this->state->pricelist;
        $acsData = [];
        $FleetID = $this->http()->_GP('fleetID', 0);
        $GetAction = $this->http()->_GP('action', "");

        $db = $this->db;

        $this->addScript('flotten.js');

        if (!empty($FleetID) && !IsVacationMode($USER)) {
            switch ($GetAction) {
                case "sendfleetback":
                    $this->fleetFunctions()->sendFleetBack($USER, $FleetID);
                    break;
                case "acs":
                    $acsData = $this->getACSPageData($FleetID);
                    break;
            }
        }

        $techExpedition = $USER[$resource[124]];

        if ($techExpedition >= 1) {
            $activeExpedition = $this->fleetFunctions()->getCurrentFleets($USER['id'], 15, true);
            $maxExpedition = floor(sqrt($techExpedition));
        } else {
            $activeExpedition = 0;
            $maxExpedition = 0;
        }

        $currentPlanetCount = $this->playerUtil()->currentPlanetCount($USER);
        $maxPlanetCount = $this->playerUtil()->maxPlanetCount($USER);

        $maxFleetSlots = $this->fleetFunctions()->getMaxFleetSlots($USER);

        $targetGalaxy = $this->http()->_GP('galaxy', (int) $PLANET['galaxy']);
        $targetSystem = $this->http()->_GP('system', (int) $PLANET['system']);
        $targetPlanet = $this->http()->_GP('planet', (int) $PLANET['planet']);
        $targetType = $this->http()->_GP('planettype', (int) $PLANET['planet_type']);
        $targetMission = $this->http()->_GP('target_mission', -1);

        $sql = "SELECT * FROM %%FLEETS%%
            WHERE `owner` = :userID AND `mission` <> :missile
            ORDER BY `return_time` ASC;";
        $fleetResult = $db->select($sql, [
            ':userID' => $USER['id'],
            ':missile' => MISSION_MISSILE,
        ]);

        $activeFleetSlots = $db->rowCount();

        $FlyingFleetList = [];

        foreach ($fleetResult as $fleetsRow) {
            $FleetList[$fleetsRow['id']] = $this->fleetFunctions()->unserialize($fleetsRow['ships']);

            if ($fleetsRow['mission'] == MISSION_STATION && $fleetsRow['status'] == FLEET_OUTWARD) {
                $returnTime = $fleetsRow['arrival_time'];
            } else {
                $returnTime = $fleetsRow['return_time'];
            }

            if ($fleetsRow['arrival_time'] > $this->state->runtime->timestamp && $fleetsRow['status'] == FLEET_OUTWARD) {
                $NextEventTime = $fleetsRow['arrival_time'];
            } elseif ($fleetsRow['stay_time'] > $this->state->runtime->timestamp && $fleetsRow['mission'] == MISSION_HOLD) {
                $NextEventTime = $fleetsRow['stay_time'];
            } else {
                $NextEventTime = $fleetsRow['return_time'];
            }

            $FlyingFleetList[$NextEventTime . sprintf('%010d', $fleetsRow['id'])] = [
                'id'                => $fleetsRow['id'],
                'mission'           => $fleetsRow['mission'],
                'state'             => $fleetsRow['status'],
                'no_returnable'     => $fleetsRow['cannotCancel'],
                'startGalaxy'       => $fleetsRow['start_galaxy'],
                'startSystem'       => $fleetsRow['start_system'],
                'startPlanet'       => $fleetsRow['start_planet'],
                'startType'         => $fleetsRow['start_type'],
                'startTime'         => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $fleetsRow['arrival_time'], $USER['timezone']),
                'endGalaxy'         => $fleetsRow['end_galaxy'],
                'endSystem'         => $fleetsRow['end_system'],
                'endPlanet'         => $fleetsRow['end_planet'],
                'endType'           => $fleetsRow['end_type'],
                'metal'             => $fleetsRow['resource_metal'],
                'crystal'           => $fleetsRow['resource_crystal'],
                'deuterium'         => $fleetsRow['resource_deuterium'],
                'endTime'           => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $fleetsRow['return_time'], $USER['timezone']),
                'amount'            => $this->viewHelpers()->prettyNumber($fleetsRow['ship_amount']),
                'returntime'        => $returnTime,
                'resttime'          => $returnTime - $this->state->runtime->timestamp,
                'startTimestamp'    => $fleetsRow['start_time'],
                'FleetList'         => $FleetList[$fleetsRow['id']],
            ];
        }

        ksort($FlyingFleetList);

        $FleetsOnPlanet = [];
        $shipinfos = [];
        foreach ($reslist['fleet'] as $FleetID) {
            $shipinfos[$FleetID] = [
                'id' => $FleetID,
                'expo' => ($pricelist[$FleetID]['cost'][901] + $pricelist[$FleetID]['cost'][902]) * 5 / 1000,
                'capacity' => $pricelist[$FleetID]['capacity'],
            ];


            if ($PLANET[$resource[$FleetID]] == 0) {
                continue;
            }

            $FleetsOnPlanet[$FleetID] = [
                'id' => $FleetID,
                'speed' => $this->fleetFunctions()->getFleetMaxSpeed($FleetID, $USER),
                'count' => $PLANET[$resource[$FleetID]],
            ];
        }

        $keys = [];
        $ids = [];

        foreach ($USER['PLANETS'] as $planet) {
            $watchKey = $resource[$planet['watch_id']];

            if ($planet['watch_id'] > 0 && $planet['watch_id'] < 100) {
                $keys[] = 'p.' . $watchKey;
                $ids[] = $planet['id'];

            } elseif ($planet['watch_id'] >= 100 && $planet['watch_id'] < 200) {
                $keys[] = 'u.' . $watchKey;
                $ids[] = $planet['id'];
            }
        }


        $watchList = [
            [
                'elementId' => 0,
                'name'      => $LNG['fl_cargo_current_planet'],
                'coords'    => $PLANET['galaxy'] . ':' . $PLANET['system'] . ':' . $PLANET['planet'] . ($PLANET['planet_type'] == 3 ? ' (M)' : ''),
                'galaxy'    => $PLANET['galaxy'],
                'system'    => $PLANET['system'],
                'planet'    => $PLANET['planet'],
                'planet_type' => $PLANET['planet_type'],
                'level'     => 0,
                'metal' => floor($PLANET['metal']),
                'crystal' => floor($PLANET['crystal']),
                'deuterium' => floor($PLANET['deuterium']),
                'total' => $PLANET['metal'] + $PLANET['crystal'] + $PLANET['deuterium'] + 1000,
            ],
        ];

        $watchList[0]['needed202'] = ceil($watchList[0]['total'] / $shipinfos[202]['capacity']);
        $watchList[0]['needed203'] = ceil($watchList[0]['total'] / $shipinfos[203]['capacity']);

        if (!empty($keys) && !empty($ids)) {

            $sql = "SELECT p.id, p.watch_id, p.metal, p.crystal, p.deuterium, p.galaxy, p.system, p.planet, p.planet_type, " . implode(',', $keys) . " FROM %%PLANETS%% p LEFT JOIN %%USERS%% u ON u.id = p.id_owner WHERE p.id IN (" . implode(',', $ids) . ");";
            $watchLevels = $db->select($sql);

            foreach ($watchLevels as $levelRow) {
                $cost = $this->buildFunctions()->getElementPrice($USER, $PLANET, $levelRow['watch_id'], false, $levelRow[$resource[$levelRow['watch_id']]] + 1);
                $restCost = [
                    'elementId' => $levelRow['watch_id'],
                    'name'      => $LNG['tech'][$levelRow['watch_id']],
                    'coords'    => $levelRow['galaxy'] . ':' . $levelRow['system'] . ':' . $levelRow['planet'] . ($levelRow['planet_type'] == 3 ? ' (M)' : ''),
                    'galaxy'    => $levelRow['galaxy'],
                    'system'    => $levelRow['system'],
                    'planet'    => $levelRow['planet'],
                    'planet_type' => $levelRow['planet_type'],
                    'level'     => $levelRow[$resource[$levelRow['watch_id']]] + 1,
                    'metal'     => ceil(max(0, $cost[901] - $levelRow['metal']) / 10) * 10,
                    'crystal'   => ceil(max(0, $cost[902] - $levelRow['crystal']) / 10) * 10,
                    'deuterium' => ceil(max(0, $cost[903] - $levelRow['deuterium']) / 10) * 10,
                ];

                $restCost['total'] = $restCost['metal'] + $restCost['crystal'] + $restCost['deuterium'] + 1000;

                $restCost['needed202'] = ceil($restCost['total'] / $shipinfos[202]['capacity']);
                $restCost['needed203'] = ceil($restCost['total'] / $shipinfos[203]['capacity']);

                $watchList[$levelRow['id']] = $restCost;
            }
        }

        $this->assign([
            'FleetsOnPlanet'        => $FleetsOnPlanet,
            'FlyingFleetList'       => $FlyingFleetList,
            'activeExpedition'      => $activeExpedition,
            'maxExpedition'         => $maxExpedition,
            'currentPlanetCount'    => $currentPlanetCount,
            'maxPlanetCount'        => $maxPlanetCount,
            'activeFleetSlots'      => $activeFleetSlots,
            'maxFleetSlots'         => $maxFleetSlots,
            'targetGalaxy'          => $targetGalaxy,
            'targetSystem'          => $targetSystem,
            'targetPlanet'          => $targetPlanet,
            'targetType'            => $targetType,
            'targetMission'         => $targetMission,
            'acsData'               => $acsData,
            'isVacation'            => IsVacationMode($USER),
            'bonusAttack'           => $USER[$resource[109]] * 10,
            'bonusShield'           => $USER[$resource[110]] * 10,
            'bonusDefensive'        => $USER[$resource[111]] * 10,
            'bonusCombustion'       => $USER[$resource[115]] * 10,
            'bonusImpulse'          => $USER[$resource[117]] * 20,
            'bonusHyperspace'       => $USER[$resource[118]] * 30,
            'maxExpo'               => $this->fleetFunctions()->calculateMaxFactorRes($this->universe()->current()),
            'shiptypes'             => $shipinfos,
            'watchList'             => $watchList,
        ]);

        return $this->render('game/page.fleetTable.default.html.twig');
    }
}
