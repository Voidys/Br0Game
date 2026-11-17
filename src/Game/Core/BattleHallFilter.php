<?php

/**
 *
 * Filter functions for the battlehall page
 *
 * initial purpose:
 * enable battlehall filtering by:
 * - memorial mode (AbschiedsKB all/only/exclude)
 * - diplomacy (all/alliance/self)
 * - location (galaxy)
 * - time (all/day/week/month)
 *
 * GENERAL RULE: 0 = all / no filters
 *
 */

namespace App\Game\Core;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Query builder of the battle hall (top battle reports, table topkb): filters by memorial status (all/excluded/only),
 * diplomacy (all, own alliance's players, own fights only), timeframe (24 h, week, month; reports appear after a
 * 6 hour delay) and galaxy, from the filter array chosen on the battle hall page.
 */
class BattleHallFilter
{
    use \App\Game\Core\GameUtilities;

    // memorial KBs
    public const MEMORIAL_ALL = 0;
    public const MEMORIAL_EXCLUDE = 1;
    public const MEMORIAL_ONLY = 2;

    // diplomacy
    public const DIPLOMACY_ALL = 0;
    public const DIPLOMACY_ALLIANCE = 1;
    public const DIPLOMACY_SELF = 2;

    // time
    public const TIMEFRAME_ALL = 0;
    public const TIMEFRAME_24H = 1;
    public const TIMEFRAME_1WK = 2;
    public const TIMEFRAME_1MTH = 3;

    private int $_memorial = 0;
    private int $_diplomacy = 0;
    private int $_timeframe = 0;
    private int $_location = 0;

    /**
     * @param array{memorial?:int, diplomacy?:int, timeframe?:int, galaxy?:int}|null $filterArray selected filters, null = no filter.
     */
    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
        ?array $filterArray = null,
    ) {
        if ($filterArray != null) {
            $this->_memorial = $filterArray['memorial'] ?: 0;
            $this->_diplomacy = $filterArray['diplomacy'] ?: 0;
            $this->_timeframe = $filterArray['timeframe'] ?: 0;
            $this->_location = $filterArray['galaxy'] ?: 0;
        }
    }

    /** Adds the memorial condition: only normal reports, only memorial reports, or (all) nothing. */
    private function filterMemorialKb(QueryBuilder $qb, int $memorial): void
    {
        switch ($memorial) {
            case self::MEMORIAL_EXCLUDE:
                $qb->andWhere('topkb.memorial = 0');
                break;
            case self::MEMORIAL_ONLY:
                $qb->andWhere('topkb.memorial = 1');
                break;
        }
    }

    /**
     * Adds the time condition: reports newer than the timeframe plus the 6 hour battle hall delay; nothing for all time.
     */
    private function assembleTimeFilter(QueryBuilder $qb, int $timeframe): void
    {
        switch ($timeframe) {
            case self::TIMEFRAME_24H:
                $time = TIME_24_HOURS;
                break;
            case self::TIMEFRAME_1WK:
                $time = TIME_1_WEEK;
                break;
            case self::TIMEFRAME_1MTH:
                $time = TIME_1_MONTH;
                break;
            default:
                return;
        }

        $time += TIME_6_HOURS; // 6h battlehall delay
        $qb->andWhere('topkb.time > UNIX_TIMESTAMP() - ' . (int) $time);
    }

    /**
     * Adds the condition restricting to reports in which the current player (or any member of the own alliance) took part;
     * nothing without alliance or for "all".
     */
    private function assembleDiplomacyFilter(QueryBuilder $qb, int $diplomacy): void
    {
        $USER = $this->state->USER;
        $topkb = $this->db->table('TOPKB');
        $participants = $this->db->table('TOPKB_USERS');

        switch ($diplomacy) {
            case self::DIPLOMACY_SELF:
                $qb->andWhere('topkb.rid IN (SELECT DISTINCT t.rid FROM ' . $topkb . ' t JOIN ' . $participants . ' tu ON t.rid = tu.rid WHERE tu.uid = :diplomacyUser)')
                    ->setParameter('diplomacyUser', (int) $USER['id']);
                break;
            case self::DIPLOMACY_ALLIANCE:
                $allyid = (int) $USER['ally_id'];
                if (empty($allyid) || $allyid == 0) {
                    return;
                } // no alliance

                $qb->andWhere('topkb.rid IN (SELECT DISTINCT t.rid FROM ' . $topkb . ' t JOIN ' . $participants . ' tu ON t.rid = tu.rid'
                    . ' WHERE tu.uid IN (SELECT `id` FROM ' . $this->db->table('USERS') . ' WHERE `ally_id` = :diplomacyAlly))')
                    ->setParameter('diplomacyAlly', $allyid);
                break;
        }
    }

    private function assembleLocationFilter(QueryBuilder $qb, int $galaxy): void
    {
        if ($galaxy == 0 || !is_numeric($galaxy)) {
            return;
        }
        $qb->andWhere('topkb.galaxy = :galaxy')->setParameter('galaxy', $galaxy);
    }

    /**
     * The top reports (most units destroyed first, ties: older first) matching the filters, with attacker and defender names
     * joined as "A & B". QueryBuilder query: base conditions (universe, 6 hour delay) plus the optional memorial, time,
     * galaxy and diplomacy filters; attacker and defender are correlated subselects over topkb_users.
     * @param int $limit maximum number of reports.
     * @return array<mixed>
     */
    public function getTopKBs(int $limit = 100): array
    {
        $db = $this->db;
        $users = $db->table('USERS');
        $participants = $db->table('TOPKB_USERS');

        $attacker = "(SELECT DISTINCT IF($participants.username = '', GROUP_CONCAT($users.username SEPARATOR ' & '), GROUP_CONCAT($participants.username SEPARATOR ' & '))"
            . " FROM $participants LEFT JOIN $users ON uid = $users.id"
            . " WHERE $participants.rid = topkb.rid AND role = 1) AS attacker";
        $defender = "(SELECT DISTINCT IF($participants.username = '', GROUP_CONCAT($users.username SEPARATOR ' & '), GROUP_CONCAT($participants.username SEPARATOR ' & '))"
            . " FROM $participants INNER JOIN $users ON uid = id"
            . " WHERE $participants.rid = topkb.`rid` AND `role` = 2) AS defender";

        $qb = $db->qb()
            ->select('topkb.*', $attacker, $defender)
            ->from($db->table('TOPKB'), 'topkb')
            ->where('universe = :universe')
            ->andWhere('time < UNIX_TIMESTAMP() - 21600')
            ->setParameter('universe', $this->universe()->current());
        $this->filterMemorialKb($qb, $this->_memorial);
        $this->assembleTimeFilter($qb, $this->_timeframe);
        $this->assembleLocationFilter($qb, $this->_location);
        $this->assembleDiplomacyFilter($qb, $this->_diplomacy);

        $top = $db->fetchAll(
            $qb->orderBy('topkb.units', 'DESC')->addOrderBy('topkb.time', 'ASC')->setMaxResults($limit),
        );

        return $top;
    }
}
