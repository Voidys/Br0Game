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
use App\Game\Core\StoredData;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\BuildFunctions;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\FlyingFleetsTable;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Session;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Planet overview: planet data, fleet events, news and rank; planet actions, rename and give-up (modes: show,
 * actions, rename, delete).
 */
#[\App\Routing\GamePage('game.php', 'overview', default: true)]
#[\App\Routing\GamePage('game.php', 'overview', default: true, uniPrefix: true)]
class OverviewController extends AbstractGameController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
    }

    private function getFleets()
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $fleetTableObj = new FlyingFleetsTable($this->db, $this->configs, $this->state);
        $fleetTableObj->setUser($USER['id']);
        $fleetTableObj->setPlanet($PLANET['id']);
        return $fleetTableObj->renderTable();
    }

    /**
     * Planet overview: the planet and its moon (or parent planet), the running building, research and hangar entries, the wreck field with repair state,
     * the fleet movements of the table (FlyingFleetsTable), own rank and points, universe counters (players online in the last 15 minutes, fleets
     * in flight), online admins and the outlaw state. No request parameters besides the standard cp= planet switch.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $PLANET = $this->state->PLANET;
        $USER = $this->state->USER;
        $AdminsOnline = [];
        $AllPlanets = [];
        $Moon = [];
        $RefLinks = [];

        $db = $this->db;

        foreach ($USER['PLANETS'] as $ID => $CPLANET) {
            if ($ID == $PLANET['id']) {
                continue;
            }

            if (!empty($CPLANET['b_building']) && !empty($CPLANET['b_building_id']) && $CPLANET['b_building'] > $this->state->runtime->timestamp) {
                $Queue = StoredData::decode($CPLANET['b_building_id']);
                $timeleft = $USER['urlaubs_modus'] ? ($CPLANET['b_building'] - $USER['urlaubs_start']) : ($CPLANET['b_building'] - $this->state->runtime->timestamp);
                $BuildPlanet = [
                    'id'        => $Queue[0][0],
                    'level'     => $Queue[0][1],
                    'timeleft'  => $timeleft,
                    'time'      => $CPLANET['b_building'],
                    'starttime' => $this->viewHelpers()->prettyTime($timeleft),
                    'mode'      => $Queue[0][4],
                ];
            } else {
                $BuildPlanet = false;
            }

            $AllPlanets[] = [
                'id'            => $CPLANET['id'],
                'name'          => $CPLANET['name'],
                'image'         => $CPLANET['image'],
                'planet_style'  => $CPLANET['planet_style'],
                'build'         => $BuildPlanet,
                'type'          => $CPLANET['planet_type'],
                'galaxy'        => $CPLANET['galaxy'],
                'system'        => $CPLANET['system'],
                'planet'        => $CPLANET['planet'],
            ];
        }

        if ($PLANET['id_luna'] != 0) {
            $sql = "SELECT id, name, image, planet_style, planet_type, b_building_id, b_building FROM %%PLANETS%% WHERE id = :lunaID;";
            $Moon = $db->selectSingle($sql, [':lunaID'   => $PLANET['id_luna']]);
        } elseif ($PLANET['planet_type'] == 3) {
            $sql = "SELECT id, name, image, planet_style, planet_type, b_building_id, b_building FROM %%PLANETS%% WHERE id_luna = :id;";
            $Moon = $db->selectSingle($sql, [':id'   => $PLANET['id']]);
        }
        if (!empty($Moon)) {
            if (!empty($Moon['b_building']) && !empty($Moon['b_building_id']) && $Moon['b_building'] > $this->state->runtime->timestamp) {
                $Queue = StoredData::decode($Moon['b_building_id']);
                $timeleft = $USER['urlaubs_modus'] ? ($Moon['b_building'] - $USER['urlaubs_start']) : ($Moon['b_building'] - $this->state->runtime->timestamp);
                $Moon['build'] = [
                    'id'        => $Queue[0][0],
                    'level'     => $Queue[0][1],
                    'timeleft'  => $timeleft,
                    'time'      => $Moon['b_building'],
                    'starttime' => $this->viewHelpers()->prettyTime($timeleft),
                    'mode'      => $Queue[0][4],
                ];
            } else {
                $Moon['build'] = false;
            }
        }

        if (!empty($PLANET['b_building']) && !empty($PLANET['b_building_id'])) {
            $Queue          = StoredData::decode($PLANET['b_building_id']);
            $timeleft = $USER['urlaubs_modus'] ? ($PLANET['b_building'] - $USER['urlaubs_start']) : ($PLANET['b_building'] - $this->state->runtime->timestamp);
            $buildInfo['buildings'] = [
                'id'        => $Queue[0][0],
                'level'     => $Queue[0][1],
                'timeleft'  => $timeleft,
                'time'      => $PLANET['b_building'],
                'starttime' => $this->viewHelpers()->prettyTime($timeleft),
                'mode'      => $Queue[0][4],
            ];
        } else {
            $buildInfo['buildings'] = false;
        }

        if (!empty($PLANET['b_hangar_id'])) {
            $Queue  = StoredData::decode($PLANET['b_hangar_id']);
            $time   = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $Queue[0][0]) * $Queue[0][1];
            $buildInfo['fleet'] = [
                'id'        => $Queue[0][0],
                'level'     => $Queue[0][1],
                'timeleft'  => $time - $PLANET['b_hangar'],
                'time'      => $time,
                'starttime' => $this->viewHelpers()->prettyTime($time - $PLANET['b_hangar']),
            ];
        } else {
            $buildInfo['fleet'] = false;
        }

        if (!empty($USER['b_tech_id']) && !empty($USER['b_tech_queue'])) {
            $timeleft = $USER['urlaubs_modus'] ? ($USER['b_tech'] - $USER['urlaubs_start']) : ($USER['b_tech'] - $this->state->runtime->timestamp);
            $Queue = StoredData::decode($USER['b_tech_queue']);
            $buildInfo['tech'] = [
                'id'        => $Queue[0][0],
                'level'     => $Queue[0][1],
                'timeleft'  => $timeleft,
                'time'      => $USER['b_tech'],
                'starttime' => $this->viewHelpers()->prettyTime($timeleft),
            ];
        } else {
            $buildInfo['tech']  = false;
        }

        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID;";
        $wreckfield = $db->selectSingle($sql, [':planetID' => $PLANET['id']]);
        if (!empty($wreckfield['repair_order'])) {
            $repairing = $wreckfield['repair_order_end'] > $this->state->runtime->timestamp;
            $timeleft = $USER['urlaubs_modus'] ? ($wreckfield['repair_order_end'] - $USER['urlaubs_start']) : ($wreckfield['repair_order_end'] - $this->state->runtime->timestamp);
            $deploy_timeleft = $USER['urlaubs_modus'] ? ($wreckfield['repair_order_end'] + TIME_72_HOURS - $USER['urlaubs_start']) : ($wreckfield['repair_order_end'] + TIME_72_HOURS - $this->state->runtime->timestamp);
            $buildInfo['repair'] = [
                'text'          => $LNG['bd_repairing'],
                'deploy_text'   => $LNG['bd_deployable'],
                'timeleft'      => $timeleft + ($repairing ? 0 : TIME_72_HOURS),
                'time'          => $wreckfield['repair_order_end'] + ($repairing ? 0 : TIME_72_HOURS),
                'starttime'     => $this->viewHelpers()->prettyTime($repairing ? $timeleft : $deploy_timeleft),
                'repairing'     => $repairing,
            ];
        } else {
            $buildInfo['repair']  = false;
        }

        $sql = "SELECT id,username FROM %%USERS%%
            WHERE universe = :universe AND onlinetime >= :onlinetime AND authlevel > :authlevel;";
        $onlineAdmins = $db->select($sql, [
            ':universe'     => $this->universe()->current(),
            ':onlinetime'   => $this->state->runtime->timestamp - 10 * 60,
            ':authlevel'    => AUTH_USR,
        ]);

        foreach ($onlineAdmins as $AdminRow) {
            $AdminsOnline[$AdminRow['id']]  = $AdminRow['username'];
        }

        // known issue: deleted players no longer show up in this table
        $sql = "SELECT u.id, u.username, s.total_points FROM %%USERS%% as u
		    LEFT JOIN %%STATPOINTS%% as s ON s.id_owner = u.id AND s.stat_type = '1'
            WHERE ref_id = :userID;";
        $RefLinksRAW = $db->select($sql, [':userID'   => $USER['id']]);

        $config = $this->configs->get();

        if ($config->ref_active) {
            foreach ($RefLinksRAW as $RefRow) {
                $RefLinks[$RefRow['id']] = [
                    'username'  => $RefRow['username'],
                    'points'    => min($RefRow['total_points'], $config->ref_minpoints),
                ];
            }
        }

        $sql    = 'SELECT total_points, total_rank FROM %%STATPOINTS%%
		    WHERE id_owner = :userId AND stat_type = :statType';

        $statData   = $db->selectSingle($sql, [
            ':userId'   => $USER['id'],
            ':statType' => 1,
        ]);

        // the template formats ov_userrank_info with these values (null: not ranked yet)
        $rankInfo = null;
        if (($statData['total_rank'] ?? 0) != 0) {
            $rankInfo = [
                'points'     => $this->viewHelpers()->prettyNumber($statData['total_points']),
                'rank'       => $statData['total_rank'],
                'usersCount' => $config->users_amount,
            ];
        }

        $sql = 'SELECT COUNT(*) AS amount FROM %%USERS%%
            WHERE onlinetime >= UNIX_TIMESTAMP(NOW() - INTERVAL 15 MINUTE)';
        $usersOnline = $db->selectSingle($sql, [], 'amount');

        $sql = 'SELECT COUNT(*) AS amount FROM %%FLEETS%%';
        $fleetsOnline = $db->selectSingle($sql, [], 'amount');
        $enablePlanetStyles = $USER['enable_planet_styles'];

        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $outlawTime = null;
        $outlawActivations = $statsHandler->getValue($USER['id'], AdvancedStatsEnum::OUTLAW_ACTIVATIONS);
        if ($outlawActivations > 1 && $this->state->runtime->timestamp - $USER['outlaw_time'] <= 7 * TIME_24_HOURS) {
            $outlawTime = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $USER['outlaw_time'] + 7 * TIME_24_HOURS, $USER['timezone']);
        } elseif ($outlawActivations == 1 && $this->state->runtime->timestamp - $USER['outlaw_time'] <= 2 * TIME_24_HOURS) {
            $outlawTime = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $USER['outlaw_time'] + 2 * TIME_24_HOURS, $USER['timezone']);
        }

        $this->assign([
            'umode'                     => $USER['urlaubs_modus'],
            'rankInfo'                  => $rankInfo,
            'is_news'                   => $config->overview_news_frame,
            'news'                      => makebr($config->overview_news_text),
            'usersOnline'               => $usersOnline,
            'fleetsOnline'              => $fleetsOnline,
            'planetname'                => $PLANET['name'],
            'planetimage'               => $PLANET['image'],
            'planet_style'              => $PLANET['planet_style'],
            'galaxy'                    => $PLANET['galaxy'],
            'system'                    => $PLANET['system'],
            'planet'                    => $PLANET['planet'],
            'planet_type'               => $PLANET['planet_type'],
            'username'                  => $USER['username'],
            'userid'                    => $USER['id'],
            'buildInfo'                 => $buildInfo,
            'Moon'                      => $Moon,
            'fleets'                    => $this->getFleets(),
            'AllPlanets'                => $AllPlanets,
            'AdminsOnline'              => $AdminsOnline,
            'planet_diameter'           => $this->viewHelpers()->prettyNumber($PLANET['diameter']),
            'planet_field_current'      => $PLANET['field_current'],
            'planet_field_max'          => $this->playerUtil()->calculateMaxPlanetFields($PLANET),
            'planet_temp_min'           => $PLANET['temp_min'],
            'planet_temp_max'           => $PLANET['temp_max'],
            'ref_active'                => $config->ref_active,
            'ref_minpoints'             => $config->ref_minpoints,
            'RefLinks'                  => $RefLinks,
            'servertime'                => $this->viewHelpers()->formatDate("M D d H:i:s", $this->state->runtime->timestamp, $USER['timezone']),
            'path'                      => $this->state->runtime->httpPath,
            'colors'                    => $USER['colors'],
            'enablePlanetStyles'        => $enablePlanetStyles,
            'isCatchUp'                 => $config->catch_up,
            'catchUp'                   => $LNG->format('sys_catchup', round((1 - $this->playerUtil()->getCatchUpFactor($USER)) * 100, 2)),
            'outlawTime'                => $outlawTime,
        ]);

        return $this->render('game/page.overview.default.html.twig');
    }

    /**
     * Popup with the planet actions (rename, abandon); passes the confirmation text and whether this is the main planet.
     */
    public function actions(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $this->setWindow('popup');

        $this->assign([
            'ov_security_confirm' => $LNG->format('ov_security_confirm', $PLANET['name'] . ' ['
                . $PLANET['galaxy'] . ':' . $PLANET['system'] . ':' . $PLANET['planet'] . ']'),
            'mainPlanet' => $USER['id_planet'],
            'currentPlanet' => $PLANET['id'],
        ]);
        return $this->render('game/page.overview.actions.html.twig');
    }

    /**
     * Renames the current planet (XHR, request: name): at most 20 characters and valid characters; answers JSON {message, error}.
     */
    public function rename()
    {
        $LNG = $this->state->LNG;
        $PLANET = $this->state->PLANET;
        $newname        = $this->http()->_GP('name', '', UTF8_SUPPORT);
        if (!empty($newname)) {
            if (mb_strlen($newname) > 20) {
                // PLANETS.name is varchar(20)
                $this->sendJSON(['message' => $LNG->format('ov_newname_too_long', 20), 'error' => true]);
            } elseif (!$this->playerUtil()->isNameValid($newname, true)) {
                $this->sendJSON(['message' => $LNG['ov_newname_specialchar'], 'error' => true]);
            } else {
                $db = $this->db;
                $sql = "UPDATE %%PLANETS%% SET name = :newName WHERE id = :planetID;";
                $db->update($sql, [
                    ':newName'  => $newname,
                    ':planetID' => $PLANET['id'],
                ]);

                $this->sendJSON(['message' => $LNG['ov_newname_done'], 'error' => false]);
            }
        }
    }

    /**
     * Abandons the current planet (XHR, request: password, which is actually the planet's name typed as confirmation). Refused while own fleets
     * start from the planet or its moon, and for the main planet. Research queued on the planet is re-homed to the main planet, then the planet is
     * deleted (PlayerUtil::deletePlanet); answers JSON.
     */
    public function delete()
    {
        $LNG = $this->state->LNG;
        $PLANET = $this->state->PLANET;
        $password   = $this->http()->_GP('password', '', true);

        if (!empty($password)) {
            $db = $this->db;
            $sql = "SELECT COUNT(*) as state FROM %%FLEETS%% WHERE
                      (`owner` = :userID AND (`start_id` = :planetID OR `start_id` = :lunaID));";
            $IfFleets = $db->selectSingle($sql, [
                ':userID'   => $this->state->USER['id'],
                ':planetID' => $PLANET['id'],
                ':lunaID'   => $PLANET['id_luna'],
            ], 'state');

            if ($IfFleets > 0) {
                $this->sendJSON(['message' => $LNG['ov_abandon_planet_not_possible']]);
            } elseif ($this->state->USER['id_planet'] == $PLANET['id']) {
                $this->sendJSON(['message' => $LNG['ov_principal_planet_cant_abanone']]);
                // } elseif (!password_verify($password, $accPassword)) {
                //     $this->sendJSON(['message' => $LNG['ov_wrong_pass']]);
            } elseif ($password != $PLANET['name']) {
                $this->sendJSON(['message' => $LNG['ov_wrong_name']]);
            } else {
                // Re-home research queued on the abandoned planet to the main planet and persist it
                if (!empty($this->state->USER['b_tech_queue'])) {
                    $TechQueue = StoredData::decode($this->state->USER['b_tech_queue']);
                    foreach ($TechQueue as $ID => $ListIDArray) {
                        if ($ListIDArray[4] == $PLANET['id']) {
                            $TechQueue[$ID][4] = $this->state->USER['id_planet'];
                        }
                    }

                    if ($this->state->USER['b_tech_planet'] == $PLANET['id']) {
                        $this->state->USER['b_tech_planet'] = $this->state->USER['id_planet'];
                    }

                    $this->state->USER['b_tech_queue'] = StoredData::encode($TechQueue);
                    $this->resourceUpdate()->saveTechQueue($this->state->USER);
                }

                $this->playerUtil()->deletePlanet($PLANET['id']);
                $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
                $statsHandler->increment($this->state->USER['id'], $PLANET['planet_type'] === TYPE_MOON ? AdvancedStatsEnum::MOONS_DELETED : AdvancedStatsEnum::PLANETS_DELETED, 1);

                $session = $this->sessions()->load();
                $session->planetId = $this->state->USER['id_planet'];
                $session->save();
                $this->sendJSON(['ok' => true, 'message' => $LNG['ov_planet_abandoned']]);
            }
        }
    }
}
