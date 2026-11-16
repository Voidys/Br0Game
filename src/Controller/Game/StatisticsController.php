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
use App\Game\Cronjob\Cronjob;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Highscore pages for players and alliances (points, fleet, research, buildings, defense).
 */
#[\App\Routing\GamePage('game.php', 'statistics')]
#[\App\Routing\GamePage('game.php', 'statistics', uniPrefix: true)]
class StatisticsController extends AbstractGameController
{
    public static $requireModule = 'MODULE_STATISTICS';

    public function initialize(): void
    {
        parent::initialize();
    }

    public function show(): Response
    {
        $USER = & $this->state->USER;
        $LNG = $this->state->LNG;
        $who = $this->http()->_GP('who', STATS_PLAYERS);
        $type = $this->http()->_GP('type', STATS_TYPE_POINTS);
        $range = $this->http()->_GP('range', 1);
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_SPYTECH_DEPENDENT_STATS) || $USER['authlevel'] > 0) {
            switch ($type) {
                case STATS_TYPE_FLEET:
                    $Order = "fleet_rank";
                    $Points = "fleet_points";
                    $Rank = "fleet_rank";
                    $OldRank = "fleet_old_rank";
                    break;
                case STATS_TYPE_RESEARCH:
                    $Order = "tech_rank";
                    $Points = "tech_points";
                    $Rank = "tech_rank";
                    $OldRank = "tech_old_rank";
                    break;
                case STATS_TYPE_BUILDINGS:
                    $Order = "build_rank";
                    $Points = "build_points";
                    $Rank = "build_rank";
                    $OldRank = "build_old_rank";
                    break;
                case STATS_TYPE_DEFENSES:
                    $Order = "defs_rank";
                    $Points = "defs_points";
                    $Rank = "defs_rank";
                    $OldRank = "defs_old_rank";
                    break;
                case STATS_TYPE_UNITS:
                    $Order = "units_rank";
                    $Points = "units_points";
                    $Rank = "units_rank";
                    $OldRank = "units_old_rank";
                    break;
                case STATS_TYPE_HONOR:
                    $Order = "honor_rank";
                    $Points = "honor_points";
                    $Rank = "honor_rank";
                    $OldRank = "honor_old_rank";
                    break;
                default:
                    $Order = "total_rank";
                    $Points = "total_points";
                    $Rank = "total_rank";
                    $OldRank = "total_old_rank";
                    break;
            }
        } else {
            //prevent url manipulation
            $spytech = $USER['spy_tech'];
            if ($type == STATS_TYPE_FLEET && $spytech >= 6) {
                $Order = "fleet_rank ASC";
                $Points = "fleet_points";
                $Rank = "fleet_rank";
                $OldRank = "fleet_old_rank";
            } elseif ($type == STATS_TYPE_RESEARCH && $spytech >= 4) {
                $Order = "tech_rank ASC";
                $Points = "tech_points";
                $Rank = "tech_rank";
                $OldRank = "tech_old_rank";
            } elseif ($type == STATS_TYPE_BUILDINGS && $spytech >= 2) {
                $Order = "build_rank ASC";
                $Points = "build_points";
                $Rank = "build_rank";
                $OldRank = "build_old_rank";
            } elseif ($type == STATS_TYPE_DEFENSES && $spytech >= 6) {
                $Order = "defs_rank ASC";
                $Points = "defs_points";
                $Rank = "defs_rank";
                $OldRank = "defs_old_rank";
            } elseif ($type == STATS_TYPE_UNITS && $spytech >= 6) {
                $Order = "units_rank ASC";
                $Points = "units_points";
                $Rank = "units_rank";
                $OldRank = "units_old_rank";
            } elseif ($type == STATS_TYPE_HONOR && $spytech >= 6) {
                $Order = "honor_rank ASC";
                $Points = "honor_points";
                $Rank = "honor_rank";
                $OldRank = "honor_old_rank";
            } else {
                $Order = "total_rank ASC";
                $Points = "total_points";
                $Rank = "total_rank";
                $OldRank = "total_old_rank";
            }
        }

        $RangeList = [];

        $db = $this->db;
        $config = $this->configs->get();

        switch ($who) {
            case STATS_PLAYERS:
                $MaxUsers = 0;
                $sql = "SELECT COUNT(*) as state FROM %%USERS%% WHERE `universe` = :universe";
                if ($config->stat == 2) {
                    $sql .= " AND authlevel < :authLevel";
                    $MaxUsers = $db->selectSingle($sql, [':universe' => $this->universe()->current(), ':authLevel' => $config->stat_level], 'state');
                } else {
                    $MaxUsers = $db->selectSingle($sql, [':universe' => $this->universe()->current()], 'state');
                }
                $range = min($range, $MaxUsers);
                $LastPage = max(1, ceil($MaxUsers / 100));

                for ($Page = 0; $Page < $LastPage; $Page++) {
                    $PageValue = ($Page * 100) + 1;
                    $PageRange = $PageValue + 99;
                    $Selector['range'][$PageValue] = $PageValue . "-" . $PageRange;
                }

                $start = max(floor(($range - 1) / 100) * 100, 0);

                $sql = "SELECT DISTINCT s.*, u.id, u.username, u.ally_id, u.banaday, u.urlaubs_modus, u.onlinetime, u.outlaw_time, u.rebuild_protection, u.rebuild_protection_attackers,
                        a.ally_name, (a.ally_owner=u.id) as is_leader, a.ally_owner_range, r.DIPLOMATIC as is_diplo,
                        buddy.id as buddy, d.level as diploLevel, s.honor_title
                        FROM %%STATPOINTS%% as s
                        INNER JOIN %%USERS%% as u ON u.id = s.id_owner
                        LEFT JOIN %%ALLIANCE%% as a ON a.id = s.id_ally
                        LEFT JOIN %%ALLIANCE_RANK%% as r ON r.allianceID = s.id_ally AND r.rankID = u.ally_rank_id
                        LEFT JOIN %%DIPLO%% as d ON ((d.owner_1 = :allianceId AND d.owner_2 = a.id) OR (d.owner_1 = a.id AND d.owner_2 = :allianceId)) AND d.accept = 1
                        LEFT JOIN %%BUDDY%% as buddy ON ((buddy.sender = :userId AND buddy.owner = u.id) OR (buddy.sender = u.id AND buddy.owner = :userId)) AND u.id != :userId
                        WHERE s.universe = :universe AND s.stat_type = 1";
                if ($config->stat == 2) {
                    $sql .= " AND u.authlevel < :authLevel";
                }
                $sql .= " ORDER BY " . $Order . " LIMIT :offset, :limit;";
                $query = $db->select($sql, [
                    ':allianceId'   => $USER['ally_id'],
                    ':userId'       => $USER['id'],
                    ':universe'     => $this->universe()->current(),
                    ':offset'       => $start,
                    ':limit'        => 100,
                    ':authLevel'    => $config->stat_level,
                ]);

                try {
                    $sql = 'SELECT total_points FROM %%STATPOINTS%% WHERE id_owner = :userId AND stat_type = :statType';
                    $USER += $db->selectSingle(
                        $sql,
                        [
                            ':userId'   => $USER['id'],
                            ':statType' => 1,
                        ],
                    ) ?: ['total_points' => 0];
                } catch (Exception $e) {
                    $USER['total_points'] = 0;
                }

                foreach ($query as $StatRow) {
                    $IsNoobProtec = $this->playerUtil()->checkNoobProtectionFor($this->models()->user($USER), $this->models()->user($StatRow));
                    $Class = $this->playerUtil()->userStatus($StatRow, $IsNoobProtec);
                    $AllyClass = [];
                    if (!empty($StatRow['ally_id'])) {
                        switch ($StatRow['diploLevel']) {
                            case 1:
                            case 2:
                                $AllyClass = ['member'];
                                break;
                            case 3:
                                $AllyClass = ['trade'];
                                break;
                            case 4:
                                $AllyClass = ['friend'];
                                break;
                            case 5:
                                $AllyClass = ['enemy'];
                                break;
                            case 6:
                                $AllyClass = ['secret'];
                                break;
                        }

                        if ($USER['ally_id'] == $StatRow['ally_id']) {
                            $AllyClass = ['member'];
                        }
                    }

                    $RangeList[] = [
                        'id'                => $StatRow['id'],
                        'name'              => $StatRow['username'],
                        'class'             => $Class,
                        'isBuddy'           => $StatRow['buddy'] != null,
                        'is_leader'         => $StatRow['is_leader'],
                        'is_diplo'          => $StatRow['is_diplo'],
                        'ally_owner_range'  => $StatRow['ally_owner_range'],
                        'points'            => $this->viewHelpers()->prettyNumber($StatRow[$Points]),
                        'allyid'            => $StatRow['ally_id'],
                        'allyClass'         => $AllyClass,
                        'rank'              => $StatRow[$Rank],
                        'allyname'          => $StatRow['ally_name'],
                        'ranking'           => $StatRow[$OldRank] - $StatRow[$Rank],
                        'honortitle'        => $type == STATS_TYPE_HONOR ? $StatRow['honor_title'] : '',
                    ];
                }

                break;
            case STATS_ALLIANCES:
                $sql = "SELECT COUNT(*) as state FROM %%ALLIANCE%% WHERE `ally_universe` = :universe;";
                $MaxAllys = $db->selectSingle($sql, [':universe' => $this->universe()->current()], 'state');

                $range = min($range, $MaxAllys);
                $LastPage = max(1, ceil($MaxAllys / 100));

                for ($Page = 0; $Page < $LastPage; $Page++) {
                    $PageValue = ($Page * 100) + 1;
                    $PageRange = $PageValue + 99;
                    $Selector['range'][$PageValue] = $PageValue . "-" . $PageRange;
                }

                $start = max(floor(($range - 1) / 100) * 100, 0);

                $sql = 'SELECT DISTINCT s.*, a.id, a.ally_members, a.ally_name FROM %%STATPOINTS%% as s
                        INNER JOIN %%ALLIANCE%% as a ON a.id = s.id_owner
                        WHERE universe = :universe AND stat_type = 2
                        ORDER BY ' . $Order . ' LIMIT :offset, :limit;';
                $query = $db->select($sql, [
                    ':universe' => $this->universe()->current(),
                    ':offset'   => $start,
                    ':limit'    => 100,
                ]);


                foreach ($query as $StatRow) {
                    $RangeList[] = [
                        'id'            => $StatRow['id'],
                        'name'          => $StatRow['ally_name'],
                        'members'       => $StatRow['ally_members'],
                        'rank'          => $StatRow[$Rank],
                        'mppoints'      => $this->viewHelpers()->prettyNumber(floor($StatRow[$Points] / (max($StatRow['ally_members'], 1)))),
                        'points'        => $this->viewHelpers()->prettyNumber($StatRow[$Points]),
                        'ranking'       => $StatRow[$OldRank] - $StatRow[$Rank],
                    ];
                }

                break;
        }

        $Selector['who'] = [
            STATS_PLAYERS => $LNG['st_player'],
            STATS_ALLIANCES => $LNG['st_alliance'],
        ];
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_SPYTECH_DEPENDENT_STATS) || $USER['authlevel'] > 0) {
            $Selector['type'] = [
                STATS_TYPE_POINTS => $LNG['st_points'],
                STATS_TYPE_FLEET => $LNG['st_fleets'],
                STATS_TYPE_RESEARCH => $LNG['st_researh'],
                STATS_TYPE_BUILDINGS => $LNG['st_buildings'],
                STATS_TYPE_DEFENSES => $LNG['st_defenses'],
                STATS_TYPE_UNITS => $LNG['st_units'],
            ];
            if ($this->viewHelpers()->isModuleAvailable(MODULE_HONORPOINTS)) {
                $Selector['type'][STATS_TYPE_HONOR] = $LNG['st_honor'];
            }
        } else {
            // only show selectable stat types
            $spytech = $USER['spy_tech'];
            switch ($spytech) {
                case 0:
                case 1:
                    $Selector['type'] = [
                        STATS_TYPE_POINTS => $LNG['st_points'],
                    ];
                    break;
                case 2:
                case 3:
                    $Selector['type'] = [
                        STATS_TYPE_POINTS => $LNG['st_points'],
                        STATS_TYPE_BUILDINGS => $LNG['st_buildings'],
                    ];
                    break;
                case 4:
                case 5:
                    $Selector['type'] = [
                        STATS_TYPE_POINTS => $LNG['st_points'],
                        STATS_TYPE_RESEARCH => $LNG['st_researh'],
                        STATS_TYPE_BUILDINGS => $LNG['st_buildings'],
                    ];
                    break;
                default:
                    $Selector['type'] = [
                        STATS_TYPE_POINTS => $LNG['st_points'],
                        STATS_TYPE_FLEET => $LNG['st_fleets'],
                        STATS_TYPE_RESEARCH => $LNG['st_researh'],
                        STATS_TYPE_BUILDINGS => $LNG['st_buildings'],
                        STATS_TYPE_DEFENSES => $LNG['st_defenses'],
                        STATS_TYPE_UNITS => $LNG['st_units'],
                    ];
                    if ($this->viewHelpers()->isModuleAvailable(MODULE_HONORPOINTS)) {
                        $Selector['type'][STATS_TYPE_HONOR] = $LNG['st_honor'];
                    }
                    break;
            }
        }


        $this->assign([
            'Selectors'   => $Selector,
            'who'         => $who,
            'type'        => $type,
            'range'       => floor(($range - 1) / 100) * 100 + 1,
            'RangeList'   => $RangeList,
            'CUser_ally'  => $USER['ally_id'],
            'CUser_id'    => $USER['id'],
            'stat_date'   => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $this->cronjobs()->getLastExecutionTime('statistic'), $USER['timezone']),
            'ShortStatus' => [
                'vacation'      => $LNG['gl_short_vacation'],
                'banned'        => $LNG['gl_short_ban'],
                'inactive'      => $LNG['gl_short_inactive'],
                'longinactive'  => $LNG['gl_short_long_inactive'],
                'noob'          => $LNG['gl_short_newbie'],
                'strong'        => $LNG['gl_short_strong'],
                'enemy'         => $LNG['gl_short_enemy'],
                'friend'        => $LNG['gl_short_friend'],
                'member'        => $LNG['gl_short_member'],
            ],
        ]);

        return $this->render('game/page.statistics.default.html.twig');
    }
}
