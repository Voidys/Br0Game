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

    /**
     * Highscore. Request: who (STATS_PLAYERS or STATS_ALLIANCES), type (points, fleet, research, buildings, defense, units, honor; unknown = total points),
     * range (first rank of the 100-row page). With the spy-tech dependent stats module active, non-admins only get a category when their spy tech allows
     * it (buildings 2, research 4, fleet/defense/units/honor 6), otherwise the total ranking is shown. When the universe hides staff (`stat` = 2),
     * accounts with authlevel >= `stat_level` are left out. Player rows (STATPOINTS joined with user, alliance, diplomacy and buddy data) carry the
     * rank, points and the rank change (old rank - rank) and mark buddies and diplomatic partners; alliance rows list the alliances. Passes the
     * selectors, the page ranges and the time of the last statistic cron run to the template.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $who = $this->http()->_GP('who', STATS_PLAYERS);
        $type = $this->http()->_GP('type', STATS_TYPE_POINTS);
        $range = $this->http()->_GP('range', 1);
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_SPYTECH_DEPENDENT_STATS) || $this->state->USER['authlevel'] > 0) {
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
            $spytech = $this->state->USER['spy_tech'];
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
                // with stat == 2 the staff above the configured level is left out of the ranking
                $countQuery = $db->qb()
                    ->select('COUNT(*) AS state')
                    ->from($db->table('USERS'))
                    ->where('`universe` = :universe')
                    ->setParameter('universe', $this->universe()->current());
                if ($config->stat == 2) {
                    $countQuery->andWhere('authlevel < :authLevel')->setParameter('authLevel', $config->stat_level);
                }
                $MaxUsers = $db->fetchValue($countQuery);
                $range = min($range, $MaxUsers);
                $LastPage = max(1, ceil($MaxUsers / 100));

                for ($Page = 0; $Page < $LastPage; $Page++) {
                    $PageValue = ($Page * 100) + 1;
                    $PageRange = $PageValue + 99;
                    $Selector['range'][$PageValue] = $PageValue . "-" . $PageRange;
                }

                $start = max(floor(($range - 1) / 100) * 100, 0);

                // $Order is one of the fixed "<type>_rank ASC" strings chosen above, never request input
                $rankingQuery = $db->qb()
                    ->select(
                        'DISTINCT s.*',
                        'u.id',
                        'u.username',
                        'u.ally_id',
                        'u.banaday',
                        'u.urlaubs_modus',
                        'u.onlinetime',
                        'u.outlaw_time',
                        'u.rebuild_protection',
                        'u.rebuild_protection_attackers',
                        'a.ally_name',
                        '(a.ally_owner=u.id) AS is_leader',
                        'a.ally_owner_range',
                        'r.DIPLOMATIC AS is_diplo',
                        'buddy.id AS buddy',
                        'd.level AS diploLevel',
                        's.honor_title'
                    )
                    ->from($db->table('STATPOINTS'), 's')
                    ->innerJoin('s', $db->table('USERS'), 'u', 'u.id = s.id_owner')
                    ->leftJoin('s', $db->table('ALLIANCE'), 'a', 'a.id = s.id_ally')
                    ->leftJoin('s', $db->table('ALLIANCE_RANK'), 'r', 'r.allianceID = s.id_ally AND r.rankID = u.ally_rank_id')
                    ->leftJoin('a', $db->table('DIPLO'), 'd', '((d.owner_1 = :allianceId AND d.owner_2 = a.id) OR (d.owner_1 = a.id AND d.owner_2 = :allianceId)) AND d.accept = 1')
                    ->leftJoin('u', $db->table('BUDDY'), 'buddy', '((buddy.sender = :userId AND buddy.owner = u.id) OR (buddy.sender = u.id AND buddy.owner = :userId)) AND u.id != :userId')
                    ->where('s.universe = :universe')
                    ->andWhere('s.stat_type = 1')
                    ->setParameter('allianceId', $this->state->USER['ally_id'])
                    ->setParameter('userId', $this->state->USER['id'])
                    ->setParameter('universe', $this->universe()->current())
                    ->orderBy($Order)
                    ->setFirstResult((int) $start)
                    ->setMaxResults(100);
                if ($config->stat == 2) {
                    $rankingQuery->andWhere('u.authlevel < :authLevel')->setParameter('authLevel', $config->stat_level);
                }
                $query = $db->fetchAll($rankingQuery);

                try {
                    $sql = 'SELECT total_points FROM %%STATPOINTS%% WHERE id_owner = :userId AND stat_type = :statType';
                    $this->state->USER += $db->selectSingle(
                        $sql,
                        [
                            ':userId'   => $this->state->USER['id'],
                            ':statType' => 1,
                        ],
                    ) ?: ['total_points' => 0];
                } catch (Exception $e) {
                    $this->state->USER['total_points'] = 0;
                }

                foreach ($query as $StatRow) {
                    $IsNoobProtec = $this->playerUtil()->checkNoobProtectionFor($this->models()->user($this->state->USER), $this->models()->user($StatRow));
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

                        if ($this->state->USER['ally_id'] == $StatRow['ally_id']) {
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

                $query = $db->fetchAll(
                    $db->qb()
                        ->select('DISTINCT s.*', 'a.id', 'a.ally_members', 'a.ally_name')
                        ->from($db->table('STATPOINTS'), 's')
                        ->innerJoin('s', $db->table('ALLIANCE'), 'a', 'a.id = s.id_owner')
                        ->where('universe = :universe')
                        ->andWhere('stat_type = 2')
                        ->setParameter('universe', $this->universe()->current())
                        ->orderBy($Order)
                        ->setFirstResult((int) $start)
                        ->setMaxResults(100)
                );


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
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_SPYTECH_DEPENDENT_STATS) || $this->state->USER['authlevel'] > 0) {
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
            $spytech = $this->state->USER['spy_tech'];
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
            'CUser_ally'  => $this->state->USER['ally_id'],
            'CUser_id'    => $this->state->USER['id'],
            'stat_date'   => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $this->cronjobs()->getLastExecutionTime('statistic'), $this->state->USER['timezone']),
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
