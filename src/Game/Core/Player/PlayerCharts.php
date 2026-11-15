<?php

namespace App\Game\Core\Player;

use App\Game\Core\Database;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Singleton;
use App\Game\Core\GameUtilities;

/**
 * Builds the chart data (points history of a player or an alliance) shown in the player card and alliance
 * pages. Collaborators: ProtectionRules (spy tech visibility), ViewHelpers (date formatting).
 */
class PlayerCharts
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Points history of a player for charts: time labels, total/build/tech/fleet/defence points (hidden values are 0 when
     * the viewer lacks spy tech), the viewer's own total points at the same timestamps and relative/absolute score changes.
     *
     * @param int $targetUserId player shown
     * @param array $USER viewing user row
     * @param int $timespan seconds of history
     * @return array{userId: int, points: array, bars: array}
     */
    public function getChartPoints(int $targetUserId, array $USER, int $timespan = TIME_1_WEEK): array
    {
        $db = $this->db;

        $sql = "SELECT * FROM %%STATPOINTS_HISTORY%%
                WHERE `id_owner` = :userId AND `timestamp` < :timestamp AND `timestamp` > :timespan
                ORDER BY `timestamp` DESC;";
        $statpoints = $db->select($sql, [
            ':userId' => $targetUserId,
            ':timestamp' => ($targetUserId == $USER['id'] ? $this->state->runtime->timestamp : $this->state->runtime->timestamp - TIME_24_HOURS),
            ':timespan' => $this->state->runtime->timestamp - $timespan,
        ]);
        $statpoints = array_reverse($statpoints);

        $result = [];
        $result['userId'] = $targetUserId;
        $result['points'] = [
            'time' => [],
            'hoursAgo' => [],
            'total' => [],
            'build' => [],
            'tech' => [],
            'fleet' => [],
            'defence' => [],
            'ownTotal' => [],
        ];
        $result['bars'] = [
            'scoreRelative' => [],
            'scoreAbsolute' => [],
        ];

        // the viewer's own rows at the same timestamps, one query (the first row per timestamp, lowest stat_type)
        $ownByTimestamp = [];
        if (!empty($statpoints)) {
            $timestamps = array_map('intval', array_column($statpoints, 'timestamp'));
            $sql = "SELECT * FROM %%STATPOINTS_HISTORY%%
                WHERE `id_owner` = :userId AND `timestamp` IN (" . implode(', ', $timestamps) . ")
                ORDER BY `stat_type` ASC;";
            foreach ($db->select($sql, [':userId' => $USER['id']]) as $ownRow) {
                $ownByTimestamp[$ownRow['timestamp']] ??= $ownRow;
            }
        }

        $LNG = & $this->state->LNG;
        foreach ($statpoints as $row) {
            $result['points']['time'][] = $this->viewHelpers()->formatDate("M d Y H:i", $row['timestamp'], $USER['timezone']);
            $result['points']['hoursAgo'][] = $this->formatChartHoursAgo((int) $row['timestamp'], $LNG);
            $result['points']['tech'][] = ($this->protectionRules()->spyTechAllowed(4, $targetUserId, $USER) ? $row['tech_points'] : 0);
            $result['points']['build'][] = ($this->protectionRules()->spyTechAllowed(2, $targetUserId, $USER) ? $row['build_points'] : 0);
            $result['points']['defence'][] = ($this->protectionRules()->spyTechAllowed(6, $targetUserId, $USER) ? $row['defs_points'] : 0);
            $result['points']['fleet'][] = ($this->protectionRules()->spyTechAllowed(6, $targetUserId, $USER) ? $row['fleet_points'] : 0);
            $result['points']['total'][] = $row['total_points'];

            $ownStatpoints = $ownByTimestamp[$row['timestamp']] ?? null;
            $result['points']['ownTotal'][] = $ownStatpoints['total_points'] ?? 0;
        }

        for ($i = 1; $i < count($statpoints); $i++) {
            $previous = (int) ($statpoints[$i - 1]['total_points'] ?? 0);
            $current = (int) ($statpoints[$i]['total_points'] ?? 0);
            $absolute = $current - $previous;
            if ($previous > 0 && $current == 0) {
                $relative = -1;
            } elseif ($previous == 0) {
                $relative = $current > 0 ? 1 : 0;
            } else {
                $relative = $absolute / $previous;
            }
            $result['bars']['scoreRelative'][] = round($relative * 100, 2);
            $result['bars']['scoreAbsolute'][] = $absolute;
        }

        return $result;
    }

    /**
     * Total points history of all members of an alliance for charts.
     *
     * @param int $allyId
     * @param array $USER viewing user row (timezone)
     * @param int $timespan seconds of history
     * @return array
     */
    public function getAllianceChartPoints(int $allyId, array $USER, int $timespan = TIME_1_MONTH * 12): array
    {
        $db = $this->db;

        $sql = "SELECT 
                s.`id_owner`,
                u.`username`,
                s.`timestamp`, 
                s.`total_points`
            FROM %%STATPOINTS_HISTORY%% s
            JOIN %%USERS%% u ON s.`id_owner` = u.`id`
            WHERE s.`id_ally` = :allyId
            AND s.`timestamp` BETWEEN :startTime AND :now
            ORDER BY s.`timestamp`
        ";
        $rows = $db->select($sql, [
            ':allyId' => $allyId,
            ':startTime' => $this->state->runtime->timestamp - $timespan,
            ':now' => $this->state->runtime->timestamp - TIME_24_HOURS,
        ]);

        $usersData   = [];
        $allTimestamps = [];

        foreach ($rows as $r) {
            $allTimestamps[$r['timestamp']] = true;
        }

        $timestamps = array_keys($allTimestamps);
        sort($timestamps, SORT_NUMERIC);

        $LNG = & $this->state->LNG;
        $oldest = empty($timestamps) ? $this->state->runtime->timestamp : (int) $timestamps[0];
        $useDays = ($this->state->runtime->timestamp - $oldest) >= TIME_24_HOURS * 2;
        $timesFormated = [];
        $timesAgo = [];
        foreach ($timestamps as $ts) {
            $timesFormated[] = $this->viewHelpers()->formatDate("M d Y H:i", $ts, $USER['timezone']);
            $timesAgo[] = $this->formatChartAgo((int) $ts, $LNG, $useDays);
        }

        foreach ($rows as $r) {
            $uid = $r['id_owner'];
            if (!isset($usersData[$uid])) {
                $usersData[$uid] = [
                    'label'       => $r['username'],
                    'data'        => array_fill(0, count($timestamps), null),
                    'borderColor' => null,
                    'borderWidth' => 1,
                    'radius'      => 1,
                ];
            }

            $idx = array_search($r['timestamp'], $timestamps, true);
            $usersData[$uid]['data'][$idx] = (int) $r['total_points'];
        }

        $colors = ['red','orange','yellow','green','blue','indigo','white','lightgreen','lightblue'];
        $i = 0;
        foreach ($usersData as &$u) {
            $u['borderColor'] = $colors[$i % count($colors)];
            $i++;
        }
        unset($u);

        return [
            'timesFormated' => $timesFormated,
            'timesAgo'      => $timesAgo,
            'dataSets'      => array_values($usersData),
        ];
    }

    private function formatChartHoursAgo(int $timestamp, $LNG): string
    {
        return $this->formatChartAgo($timestamp, $LNG, false);
    }

    private function formatChartAgo(int $timestamp, $LNG, bool $useDays): string
    {
        $elapsed = max(0, $this->state->runtime->timestamp - $timestamp);
        if ($useDays) {
            return intdiv($elapsed, TIME_24_HOURS) . $LNG['short_day'];
        }
        return intdiv($elapsed, 3600) . $LNG['short_hour'];
    }

    private function protectionRules(): ProtectionRules
    {
        return $this->gameUtility(ProtectionRules::class);
    }
}
