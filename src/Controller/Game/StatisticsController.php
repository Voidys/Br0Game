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
#[\App\Routing\GamePage('game', 'statistics')]
#[\App\Controller\RequireModule('MODULE_STATISTICS')]
class StatisticsController extends AbstractGameController
{
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
        [$Order, $Points, $Rank, $OldRank] = $this->rankColumns($type);

        $RangeList = [];
        $Selector = [];

        switch ($who) {
            case STATS_PLAYERS:
                $MaxUsers = $this->countRankedPlayers();
                $range = min($range, $MaxUsers);
                $Selector['range'] = $this->pageRanges($MaxUsers);
                $RangeList = $this->playerRows($Order, $Points, $Rank, $OldRank, $type, $this->pageStart($range));
                break;
            case STATS_ALLIANCES:
                $MaxAllys = $this->countRankedAlliances();
                $range = min($range, $MaxAllys);
                $Selector['range'] = $this->pageRanges($MaxAllys);
                $RangeList = $this->allianceRows($Order, $Points, $Rank, $OldRank, $this->pageStart($range));
                break;
        }

        $Selector['who'] = [
            STATS_PLAYERS => $LNG['st_player'],
            STATS_ALLIANCES => $LNG['st_alliance'],
        ];
        $Selector['type'] = $this->typeSelector();

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

    /** Whether the stat categories are limited by the viewer's spy tech (module active and the viewer is no staff). */
    private function limitedBySpyTech(): bool
    {
        return $this->viewHelpers()->isModuleAvailable(MODULE_SPYTECH_DEPENDENT_STATS) && $this->state->USER['authlevel'] <= 0;
    }

    /**
     * Picks the STATPOINTS columns for the requested category. Unknown types, and categories the viewer's spy tech does not unlock
     * (only with the spy-tech module, see limitedBySpyTech()), fall back to the total ranking. The order clause is a fixed string,
     * never request input (it gets "ASC" appended in the spy-tech restricted mode, as before).
     *
     * @return array{0: string, 1: string, 2: string, 3: string} order clause, points column, rank column, old-rank column
     */
    private function rankColumns(mixed $type): array
    {
        $restricted = $this->limitedBySpyTech();
        // category => [column prefix, spy tech level needed in the restricted mode]
        $categories = [
            STATS_TYPE_FLEET     => ['fleet', 6],
            STATS_TYPE_RESEARCH  => ['tech', 4],
            STATS_TYPE_BUILDINGS => ['build', 2],
            STATS_TYPE_DEFENSES  => ['defs', 6],
            STATS_TYPE_UNITS     => ['units', 6],
            STATS_TYPE_HONOR     => ['honor', 6],
        ];
        $prefix = 'total';
        foreach ($categories as $category => [$columnPrefix, $neededSpyTech]) {
            if ($type == $category && (!$restricted || $this->state->USER['spy_tech'] >= $neededSpyTech)) {
                $prefix = $columnPrefix;
                break;
            }
        }

        return [
            $prefix . '_rank' . ($restricted ? ' ASC' : ''),
            $prefix . '_points',
            $prefix . '_rank',
            $prefix . '_old_rank',
        ];
    }

    /** First row offset of the 100-row page that contains rank $range. */
    private function pageStart(mixed $range): int
    {
        return (int) max(floor(($range - 1) / 100) * 100, 0);
    }

    /**
     * The page selector entries ("1-100", "101-200", ...) for $total ranked rows, keyed by the first rank of each page.
     *
     * @return array<int, string>
     */
    private function pageRanges(int $total): array
    {
        $ranges = [];
        $lastPage = max(1, ceil($total / 100));
        for ($page = 0; $page < $lastPage; $page++) {
            $pageValue = ($page * 100) + 1;
            $ranges[$pageValue] = $pageValue . '-' . ($pageValue + 99);
        }

        return $ranges;
    }

    /** Number of players in the ranking of the current universe (staff left out with `stat` = 2). */
    private function countRankedPlayers(): int
    {
        $db = $this->db;
        $config = $this->configs->get();
        $countQuery = $db->qb()
            ->select('COUNT(*) AS state')
            ->from($db->table('USERS'))
            ->where('`universe` = :universe')
            ->setParameter('universe', $this->universe()->current());
        if ($config->stat == 2) {
            $countQuery->andWhere('authlevel < :authLevel')->setParameter('authLevel', $config->stat_level);
        }

        return $db->fetchValue($countQuery);
    }

    /** Number of alliances in the current universe. */
    private function countRankedAlliances(): int
    {
        $sql = "SELECT COUNT(*) as state FROM %%ALLIANCE%% WHERE `ally_universe` = :universe;";

        return $this->db->selectSingle($sql, [':universe' => $this->universe()->current()], 'state');
    }

    /**
     * Template rows of one 100-row player ranking page starting at offset $start. Also adds the viewer's own total_points to
     * the USER state (needed by the newbie protection check against each row).
     *
     * @param string $Order  fixed ORDER BY clause from rankColumns()
     * @param string $Points points column, $Rank / $OldRank rank columns of the chosen category
     * @return list<array<string, mixed>>
     */
    private function playerRows(string $Order, string $Points, string $Rank, string $OldRank, mixed $type, int $start): array
    {
        $db = $this->db;

        $query = $this->rankingRows($Order, $start);

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

        $rows = [];
        foreach ($query as $StatRow) {
            $IsNoobProtec = $this->playerUtil()->checkNoobProtectionFor($this->models()->user($this->state->USER), $this->models()->user($StatRow));
            $rows[] = [
                'id'                => $StatRow['id'],
                'name'              => $StatRow['username'],
                'class'             => $this->playerUtil()->userStatus($StatRow, $IsNoobProtec),
                'isBuddy'           => $StatRow['buddy'] != null,
                'is_leader'         => $StatRow['is_leader'],
                'is_diplo'          => $StatRow['is_diplo'],
                'ally_owner_range'  => $StatRow['ally_owner_range'],
                'points'            => $this->viewHelpers()->prettyNumber($StatRow[$Points]),
                'allyid'            => $StatRow['ally_id'],
                'allyClass'         => $this->allyClass($StatRow),
                'rank'              => $StatRow[$Rank],
                'allyname'          => $StatRow['ally_name'],
                'ranking'           => $StatRow[$OldRank] - $StatRow[$Rank],
                'honortitle'        => $type == STATS_TYPE_HONOR ? $StatRow['honor_title'] : '',
            ];
        }

        return $rows;
    }

    /**
     * One 100-row page of the player ranking: STATPOINTS joined with the user, alliance, rank, diplomacy and buddy relation
     * to the viewer (staff above `stat_level` left out with `stat` = 2).
     *
     * @param string $Order fixed ORDER BY clause from rankColumns()
     * @return list<array<string, mixed>>
     */
    private function rankingRows(string $Order, int $start): array
    {
        $db = $this->db;
        $config = $this->configs->get();

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
                's.honor_title',
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
            ->setFirstResult($start)
            ->setMaxResults(100);
        if ($config->stat == 2) {
            $rankingQuery->andWhere('u.authlevel < :authLevel')->setParameter('authLevel', $config->stat_level);
        }
        return $db->fetchAll($rankingQuery);

    }

    /**
     * CSS class list for the alliance tag of a ranking row: the diplomacy level between the viewer's alliance and the row's
     * alliance (1/2 member, 3 trade, 4 friend, 5 enemy, 6 secret), "member" for the viewer's own alliance, empty without alliance.
     *
     * @param array<string, mixed> $StatRow ranking row with ally_id and diploLevel
     * @return list<string>
     */
    private function allyClass(array $StatRow): array
    {
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

        return $AllyClass;
    }

    /**
     * Template rows of one 100-row alliance ranking page starting at offset $start (points, average points per member, rank change).
     *
     * @return list<array<string, mixed>>
     */
    private function allianceRows(string $Order, string $Points, string $Rank, string $OldRank, int $start): array
    {
        $db = $this->db;
        $query = $db->fetchAll(
            $db->qb()
                ->select('DISTINCT s.*', 'a.id', 'a.ally_members', 'a.ally_name')
                ->from($db->table('STATPOINTS'), 's')
                ->innerJoin('s', $db->table('ALLIANCE'), 'a', 'a.id = s.id_owner')
                ->where('universe = :universe')
                ->andWhere('stat_type = 2')
                ->setParameter('universe', $this->universe()->current())
                ->orderBy($Order)
                ->setFirstResult($start)
                ->setMaxResults(100),
        );

        $rows = [];
        foreach ($query as $StatRow) {
            $rows[] = [
                'id'            => $StatRow['id'],
                'name'          => $StatRow['ally_name'],
                'members'       => $StatRow['ally_members'],
                'rank'          => $StatRow[$Rank],
                'mppoints'      => $this->viewHelpers()->prettyNumber(floor($StatRow[$Points] / (max($StatRow['ally_members'], 1)))),
                'points'        => $this->viewHelpers()->prettyNumber($StatRow[$Points]),
                'ranking'       => $StatRow[$OldRank] - $StatRow[$Rank],
            ];
        }

        return $rows;
    }

    /**
     * The category selector: every category for staff or without the spy-tech module, otherwise only the categories the viewer's
     * spy tech unlocks (honor only with the honor module).
     *
     * @return array<int, string> category constant => label
     */
    private function typeSelector(): array
    {
        $LNG = $this->state->LNG;
        if (!$this->limitedBySpyTech()) {
            $types = [
                STATS_TYPE_POINTS => $LNG['st_points'],
                STATS_TYPE_FLEET => $LNG['st_fleets'],
                STATS_TYPE_RESEARCH => $LNG['st_researh'],
                STATS_TYPE_BUILDINGS => $LNG['st_buildings'],
                STATS_TYPE_DEFENSES => $LNG['st_defenses'],
                STATS_TYPE_UNITS => $LNG['st_units'],
            ];
            if ($this->viewHelpers()->isModuleAvailable(MODULE_HONORPOINTS)) {
                $types[STATS_TYPE_HONOR] = $LNG['st_honor'];
            }

            return $types;
        }

        // only show selectable stat types
        switch ($this->state->USER['spy_tech']) {
            case 0:
            case 1:
                return [
                    STATS_TYPE_POINTS => $LNG['st_points'],
                ];
            case 2:
            case 3:
                return [
                    STATS_TYPE_POINTS => $LNG['st_points'],
                    STATS_TYPE_BUILDINGS => $LNG['st_buildings'],
                ];
            case 4:
            case 5:
                return [
                    STATS_TYPE_POINTS => $LNG['st_points'],
                    STATS_TYPE_RESEARCH => $LNG['st_researh'],
                    STATS_TYPE_BUILDINGS => $LNG['st_buildings'],
                ];
            default:
                $types = [
                    STATS_TYPE_POINTS => $LNG['st_points'],
                    STATS_TYPE_FLEET => $LNG['st_fleets'],
                    STATS_TYPE_RESEARCH => $LNG['st_researh'],
                    STATS_TYPE_BUILDINGS => $LNG['st_buildings'],
                    STATS_TYPE_DEFENSES => $LNG['st_defenses'],
                    STATS_TYPE_UNITS => $LNG['st_units'],
                ];
                if ($this->viewHelpers()->isModuleAvailable(MODULE_HONORPOINTS)) {
                    $types[STATS_TYPE_HONOR] = $LNG['st_honor'];
                }

                return $types;
        }
    }
}
