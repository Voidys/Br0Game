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

    private function filterMemorialKb(int $memorial): string
    {
        switch ($memorial) {
            case self::MEMORIAL_EXCLUDE:
                $result = " AND %%TOPKB%%.memorial = 0 ";
                return $result;
            case self::MEMORIAL_ONLY:
                $result = " AND %%TOPKB%%.memorial = 1 ";
                return $result;
            default:
                return '';
        }
    }

    /**
     * SQL condition: reports newer than the timeframe plus the 6 hour battle hall delay; '' for all time.
     */
    private function assembleTimeFilter(int $timeframe): string
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
                return '';
        }

        $time += TIME_6_HOURS; // 6h battlehall delay
        $result = " AND time > UNIX_TIMESTAMP() - $time ";

        return $result;
    }

    /**
     * SQL condition restricting to reports in which the current player (or any member of the own alliance) took part; '' without alliance or for "all".
     */
    private function assembleDiplomacyFilter(int $diplomacy): string
    {
        $USER = $this->state->USER;

        switch ($diplomacy) {
            case self::DIPLOMACY_SELF:
                $userid = (int) $USER['id'];
                $subquery = 'SELECT DISTINCT %%TOPKB%%.rid FROM %%TOPKB%%
						 	JOIN %%TOPKB_USERS%%
						 	ON %%TOPKB%%.rid = %%TOPKB_USERS%%.rid
						 	WHERE %%TOPKB_USERS%%.uid =' . $userid;

                $result = ' AND %%TOPKB%%.rid IN'
                        . ' (' . $subquery . ')';

                return $result;
            case self::DIPLOMACY_ALLIANCE:
                $allyid = (int) $USER['ally_id'];
                if (empty($allyid) || $allyid == 0) {
                    return '';
                } // no alliance

                $allyuserquery = 'SELECT `id` FROM %%USERS%% WHERE `ally_id` = '. $allyid;

                $subquery = "SELECT DISTINCT %%TOPKB%%.rid FROM %%TOPKB%%
						 JOIN %%TOPKB_USERS%%
						 ON %%TOPKB%%.rid = %%TOPKB_USERS%%.rid
						 WHERE %%TOPKB_USERS%%.uid IN ($allyuserquery)";

                $result = " AND %%TOPKB%%.rid IN
						 ($subquery)";

                return $result;
            default:
                return '';
        }
    }

    private function assembleLocationFilter(int $galaxy): string
    {
        if ($galaxy == 0 || !is_numeric($galaxy)) {
            return '';
        }
        $result = " AND %%TOPKB%%.galaxy = $galaxy";
        return $result;
    }

    /**
     * The top reports (most units destroyed first) matching the filters, with attacker and defender names joined as "A & B".
     * @param int $limit maximum number of reports.
     */
    public function getTopKBs(int $limit = 100): array
    {
        $memorialString  = $this->filterMemorialKb($this->_memorial);
        $timeString = $this->assembleTimeFilter($this->_timeframe);
        $locationString = $this->assembleLocationFilter($this->_location);
        $diplomacyString = $this->assembleDiplomacyFilter($this->_diplomacy);

        $db = $this->db;
        $sql = "SELECT *, (
			SELECT DISTINCT
			IF(%%TOPKB_USERS%%.username = '', GROUP_CONCAT(%%USERS%%.username SEPARATOR ' & '), GROUP_CONCAT(%%TOPKB_USERS%%.username SEPARATOR ' & '))
			FROM %%TOPKB_USERS%%
			LEFT JOIN %%USERS%% ON uid = %%USERS%%.id
			WHERE %%TOPKB_USERS%%.rid = %%TOPKB%%.rid AND role = 1
		) as attacker,
		(
			SELECT DISTINCT
			IF(%%TOPKB_USERS%%.username = '', GROUP_CONCAT(%%USERS%%.username SEPARATOR ' & '), GROUP_CONCAT(%%TOPKB_USERS%%.username SEPARATOR ' & '))
			FROM %%TOPKB_USERS%% INNER JOIN %%USERS%% ON uid = id
			WHERE %%TOPKB_USERS%%.rid = %%TOPKB%%.`rid` AND `role` = 2
		) as defender
		FROM %%TOPKB%%
		 WHERE universe = :universe AND time < UNIX_TIMESTAMP() - 21600
			$memorialString $timeString $locationString $diplomacyString
		 ORDER BY %%TOPKB%%.units DESC, %%TOPKB%%.time ASC LIMIT $limit;";

        $top = $db->select($sql, [
            ':universe' => $this->universe()->current()
        ]);

        return $top;
    }
}
