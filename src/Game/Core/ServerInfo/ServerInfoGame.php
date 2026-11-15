<?php

namespace App\Game\Core\ServerInfo;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Runtime\SafeFile;

/**
 * Game section: cronjobs, player/universe statistics and registrations.
 */
class ServerInfoGame
{
    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly ServerInfoDatabase $database,
    ) {}

    /**
     * @return array{jobs: int, stuck: list<string>, overdue: list<string>, lastAge: int|null, lastRun: string|null}
     */
    public function cron(callable $formatDate): array
    {
        $now = $this->state->runtime->timestamp;
        $stuck = [];
        $overdue = [];
        $jobs = 0;
        foreach ($this->db->select('SELECT name, nextTime, `lock` FROM %%CRONJOBS%% WHERE isActive = 1;') as $job) {
            ++$jobs;
            if (!empty($job['lock']) && (int) $job['nextTime'] < $now - 300) {
                $stuck[] = (string) $job['name'];
            } elseif ((int) $job['nextTime'] < $now - 300) {
                $overdue[] = (string) $job['name'];
            }
        }

        $last = $this->db->selectSingle('SELECT MAX(executionTime) AS t FROM %%CRONJOBS_LOG%%;', [], 't');
        $lastTs = $last ? strtotime((string) $last) : false;

        return [
            'jobs' => $jobs,
            'stuck' => $stuck,
            'overdue' => $overdue,
            'lastAge' => $lastTs !== false ? max(0, $now - $lastTs) : null,
            'lastRun' => $lastTs !== false ? (string) $formatDate($lastTs) : null,
        ];
    }

    // ------------------------------------------------------------------------------ game

    /**
     * Statistics of one universe plus a per universe overview. Indexed counts only; large tables
     * use the information_schema estimate (marked as such by the template).
     *
     * @return array<string, mixed>
     */
    public function game(int $universe, callable $formatDate, string $timezone): array
    {
        $db = $this->db;
        $now = $this->state->runtime->timestamp;
        $day = 86400;
        $tables = $this->database->tableStats();
        $prefix = $db->getTablePrefix();

        $users = $db->selectSingle(
            'SELECT COUNT(*) AS total,
                COALESCE(SUM(onlinetime >= :on15), 0) AS online,
                COALESCE(SUM(onlinetime >= :on24), 0) AS act24,
                COALESCE(SUM(onlinetime >= :on7), 0) AS act7,
                COALESCE(SUM(onlinetime >= :on30), 0) AS act30,
                COALESCE(SUM(register_time >= :reg24), 0) AS reg24,
                COALESCE(SUM(register_time >= :reg7), 0) AS reg7,
                COALESCE(SUM(register_time >= :reg30), 0) AS reg30,
                COALESCE(SUM(bana = 1), 0) AS banned,
                COALESCE(SUM(urlaubs_modus = 1), 0) AS vacation
             FROM %%USERS%% WHERE universe = :uni;',
            [
                ':on15' => $now - 900, ':on24' => $now - $day, ':on7' => $now - 7 * $day, ':on30' => $now - 30 * $day,
                ':reg24' => $now - $day, ':reg7' => $now - 7 * $day, ':reg30' => $now - 30 * $day, ':uni' => $universe,
            ],
        ) ?: [];

        $planets = $db->selectSingle(
            'SELECT COALESCE(SUM(planet_type = 1), 0) AS planets, COALESCE(SUM(planet_type = 3), 0) AS moons,
                COALESCE(SUM(metal), 0) AS metal, COALESCE(SUM(crystal), 0) AS crystal, COALESCE(SUM(deuterium), 0) AS deuterium,
                COALESCE(SUM(der_metal > 0 OR der_crystal > 0), 0) AS debris,
                COALESCE(SUM(der_metal), 0) AS debris_metal, COALESCE(SUM(der_crystal), 0) AS debris_crystal
             FROM %%PLANETS%% WHERE universe = :uni AND destruyed = 0;',
            [':uni' => $universe],
        ) ?: [];

        $accounts = $db->selectSingle('SELECT COUNT(*) AS total, COALESCE(SUM(bana = 1), 0) AS banned FROM %%ACCOUNTS%%;') ?: [];
        $alliances = (int) $db->selectSingle('SELECT COUNT(*) AS c FROM %%ALLIANCE%% WHERE ally_universe = :uni;', [':uni' => $universe], 'c');

        $fleetRows = $db->select('SELECT mission, COUNT(*) AS c FROM %%FLEETS%% WHERE universe = :uni GROUP BY mission ORDER BY mission;', [':uni' => $universe]);
        $fleetsByMission = [];
        $fleetsTotal = 0;
        foreach ($fleetRows as $row) {
            $fleetsByMission[(int) $row['mission']] = (int) $row['c'];
            $fleetsTotal += (int) $row['c'];
        }
        $acs = (int) $db->selectSingle('SELECT COUNT(DISTINCT `group`) AS c FROM %%FLEETS%% WHERE universe = :uni AND `group` > 0;', [':uni' => $universe], 'c');

        $market = (int) $db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%TRADES%% t INNER JOIN %%FLEETS%% f ON f.id = t.seller_fleet_id WHERE f.universe = :uni AND t.buyer_fleet_id IS NULL;',
            [':uni' => $universe],
            'c',
        );

        $points = $db->selectSingle(
            'SELECT COUNT(*) AS c, AVG(total_points) AS avg, MAX(total_points) AS top FROM %%STATPOINTS%% WHERE universe = :uni AND stat_type = 1;',
            [':uni' => $universe],
        ) ?: [];

        // messages and reports have no universe index: exact counts only while the table is small
        $messageEstimate = $tables[$prefix . 'messages']['rows'] ?? null;
        $messages = ['total' => $messageEstimate, 'unread' => null, 'exact' => false];
        if ($messageEstimate !== null && $messageEstimate <= 200000) {
            $row = $db->selectSingle(
                'SELECT COUNT(*) AS total, COALESCE(SUM(message_unread = 1), 0) AS unread FROM %%MESSAGES%% WHERE message_universe = :uni;',
                [':uni' => $universe],
            ) ?: [];
            $messages = ['total' => (int) ($row['total'] ?? 0), 'unread' => (int) ($row['unread'] ?? 0), 'exact' => true];
        }
        $reports = $tables[$prefix . 'raports']['rows'] ?? null;

        $bots = null;
        if (isset($tables[$prefix . 'bot_players'])) {
            $bots = (int) $db->selectSingle('SELECT COUNT(*) AS c FROM %%BOT_PLAYERS%% WHERE universe = :uni;', [':uni' => $universe], 'c');
        }

        $lastStats = $db->selectSingle(
            "SELECT MAX(l.executionTime) AS t FROM %%CRONJOBS_LOG%% l INNER JOIN %%CRONJOBS%% c ON c.cronjobID = l.cronjobId WHERE c.class = 'StatisticCronjob';",
            [],
            't',
        );
        $lastStatsTs = $lastStats ? strtotime((string) $lastStats) : false;

        return [
            'universe' => $universe,
            'users' => array_map('intval', $users),
            'bots' => $bots,
            'accounts' => array_map('intval', $accounts),
            'planets' => array_map('intval', $planets),
            'alliances' => $alliances,
            'fleetsTotal' => $fleetsTotal,
            'fleetsByMission' => $fleetsByMission,
            'acs' => $acs,
            'market' => $market,
            'messages' => $messages,
            'reports' => $reports,
            'points' => ['ranked' => (int) ($points['c'] ?? 0), 'avg' => (int) round((float) ($points['avg'] ?? 0)), 'top' => (int) ($points['top'] ?? 0)],
            'lastStats' => $lastStatsTs !== false ? (string) $formatDate($lastStatsTs) : null,
            'registrations' => $this->registrationsPerDay($universe, $timezone),
            'universes' => $this->perUniverse(),
        ];
    }

    /**
     * Registrations of the last 30 days (oldest first) with the bar height in percent.
     *
     * @return array{days: list<array{label: string, count: int, pct: int}>, total: int, max: int}
     */
    public function registrationsPerDay(int $universe, string $timezone): array
    {
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $tz = new \DateTimeZone(date_default_timezone_get());
        }
        $today = (new \DateTimeImmutable('@' . $this->state->runtime->timestamp))->setTimezone($tz)->setTime(0, 0);
        $start = $today->modify('-29 days');

        $counts = array_fill(0, 30, 0);
        $rows = $this->db->select(
            'SELECT register_time FROM %%USERS%% WHERE universe = :uni AND register_time >= :start;',
            [':uni' => $universe, ':start' => $start->getTimestamp()],
        );
        foreach ($rows as $row) {
            $date = (new \DateTimeImmutable('@' . (int) $row['register_time']))->setTimezone($tz)->setTime(0, 0);
            $index = (int) $start->diff($date)->format('%r%a');
            if ($index >= 0 && $index < 30) {
                ++$counts[$index];
            }
        }

        $max = max($counts);
        $days = [];
        foreach ($counts as $i => $count) {
            $days[] = [
                'label' => $start->modify('+' . $i . ' days')->format('d.m.'),
                'count' => $count,
                'pct' => $max > 0 ? max($count > 0 ? 4 : 0, (int) round($count / $max * 100)) : 0,
            ];
        }

        return ['days' => $days, 'total' => array_sum($counts), 'max' => $max];
    }

    /**
     * Players, planets, alliances and fleets for every universe.
     *
     * @return list<array{id: int, players: int, planets: int, alliances: int, fleets: int}>
     */
    public function perUniverse(): array
    {
        $db = $this->db;
        $result = [];
        $touch = static function (array &$result, int $id): void {
            $result[$id] ??= ['id' => $id, 'players' => 0, 'planets' => 0, 'alliances' => 0, 'fleets' => 0];
        };
        foreach (
            [
                'players' => 'SELECT universe AS u, COUNT(*) AS c FROM %%USERS%% GROUP BY universe;',
                'planets' => 'SELECT universe AS u, COUNT(*) AS c FROM %%PLANETS%% WHERE destruyed = 0 GROUP BY universe;',
                'alliances' => 'SELECT ally_universe AS u, COUNT(*) AS c FROM %%ALLIANCE%% GROUP BY ally_universe;',
                'fleets' => 'SELECT universe AS u, COUNT(*) AS c FROM %%FLEETS%% GROUP BY universe;',
            ] as $key => $sql
        ) {
            foreach ($db->select($sql) as $row) {
                $touch($result, (int) $row['u']);
                $result[(int) $row['u']][$key] = (int) $row['c'];
            }
        }
        ksort($result);

        return array_values($result);
    }

    // ------------------------------------------------------------------------------ logs
}
