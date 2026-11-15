<?php

namespace App\Game\Core;

/**
 * Data behind the admin information page: health checks, server, database, game statistics,
 * logs and caches. Read only and defensive: every probe may be unavailable (open_basedir, missing
 * privileges, no /proc), in which case the value is null and the page shows a dash. Nothing
 * returned here contains secrets (no passwords, keys, salts or webhook URLs).
 */
class ServerInfo
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const INFO = 'info';

    /** required PHP extensions (name => purpose key suffix is the extension name) */
    private const REQUIRED_EXTENSIONS = ['pdo_mysql', 'intl', 'mbstring', 'gd', 'curl', 'json', 'ctype'];
    private const OPTIONAL_EXTENSIONS = ['opcache', 'ffi', 'ds', 'redis', 'imap'];

    /** the log tail that is scanned (bytes), never the whole file */
    private const LOG_TAIL_BYTES = 2097152;

    /** @var array<string, array{rows: int, data: int, index: int}>|null */
    private ?array $tables = null;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

    // ------------------------------------------------------------------------------ health

    /**
     * @return list<array{id: string, status: string, value: string}> in display order
     */
    public function checks(string $environment, bool $debug, string $sessionDsn, callable $formatDate): array
    {
        $root = $this->state->runtime->rootPath;
        $local = $this->isLocalHost($this->state->runtime->httpHost);
        $checks = [];
        $add = static function (string $id, string $status, string $value) use (&$checks): void {
            $checks[] = ['id' => $id, 'status' => $status, 'value' => $value];
        };

        $add('php', version_compare(PHP_VERSION, '8.4.0', '>=') ? self::OK : self::FAIL, PHP_VERSION);

        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn(string $e): bool => !extension_loaded($e)));
        $add('ext_required', $missing === [] ? self::OK : self::FAIL, $missing === [] ? (string) count(self::REQUIRED_EXTENSIONS) . ' / ' . count(self::REQUIRED_EXTENSIONS) : implode(', ', $missing));

        $missingOptional = array_values(array_filter(self::OPTIONAL_EXTENSIONS, static fn(string $e): bool => !self::extensionLoaded($e)));
        $add('ext_optional', $missingOptional === [] ? self::OK : self::INFO, $missingOptional === [] ? 'all loaded' : implode(', ', $missingOptional));

        $opcache = $this->opcache();
        if ($opcache === null) {
            $add('opcache', self::WARN, 'off');
        } else {
            $add('opcache', self::OK, $opcache['used'] . ' / ' . $opcache['total'] . ', ' . $opcache['hitRate'] . ' %, ' . $opcache['scripts'] . ' scripts');
        }

        $isProd = $environment === 'prod' && !$debug;
        $add('environment', $isProd ? self::OK : ($local ? self::INFO : self::WARN), $environment . ($debug ? ' (debug)' : ''));

        $displayErrors = strtolower((string) ini_get('display_errors'));
        $displayOn = !in_array($displayErrors, ['', '0', 'off', 'false', 'no', 'stderr'], true);
        $add('display_errors', !$displayOn ? self::OK : ($local ? self::INFO : self::WARN), $displayOn ? 'on' : 'off');

        $dirs = ['var', 'var/cache', 'var/game', 'var/log', 'public/uploads/news'];
        if (str_starts_with($sessionDsn, 'file://') || $sessionDsn === '') {
            $dirs[] = 'var/sessions';
        }
        $notWritable = [];
        foreach ($dirs as $dir) {
            if (is_dir($root . $dir) && !is_writable($root . $dir)) {
                $notWritable[] = $dir;
            } elseif (!is_dir($root . $dir) && $dir !== 'public/uploads/news') {
                $notWritable[] = $dir;
            }
        }
        $add('writable', $notWritable === [] ? self::OK : self::FAIL, $notWritable === [] ? count($dirs) . ' / ' . count($dirs) : implode(', ', $notWritable));

        $installTool = file_exists($root . 'var/ENABLE_INSTALL_TOOL');
        $add('install_tool', $installTool ? self::WARN : self::OK, $installTool ? 'on' : 'off');

        $migrations = $this->migrations();
        $add('migrations', $migrations['pending'] === 0 ? self::OK : self::FAIL, $migrations['pending'] === 0 ? (string) $migrations['latest'] : $migrations['pending'] . ' pending');

        $rustLauncher = $root . 'bin/battlerust';
        $rustLib = $root . 'bin/libbattlerust.so';
        $rustOk = function_exists('proc_open') && PHP_OS_FAMILY === 'Linux' && is_executable($rustLauncher) && is_file($rustLib);
        $add('battle_engine', $rustOk ? self::OK : self::WARN, $rustOk ? 'Rust' : 'PHP fallback');

        $cron = $this->cron($formatDate);
        $cronStatus = self::OK;
        if ($cron['stuck'] !== []) {
            $cronStatus = self::FAIL;
        } elseif ($cron['overdue'] !== []) {
            $cronStatus = self::WARN;
        }
        if ($cron['lastAge'] === null) {
            $cronStatus = $cronStatus === self::OK ? self::WARN : $cronStatus;
        } elseif ($cron['lastAge'] > 900) {
            $cronStatus = self::FAIL;
        } elseif ($cron['lastAge'] > 300 && $cronStatus === self::OK) {
            $cronStatus = self::WARN;
        }
        $cronParts = [];
        if ($cron['stuck'] !== []) {
            $cronParts[] = 'locked: ' . implode(', ', $cron['stuck']);
        }
        if ($cron['overdue'] !== []) {
            $cronParts[] = 'overdue: ' . implode(', ', $cron['overdue']);
        }
        $cronParts[] = $cron['lastAge'] === null ? 'never run' : 'last run ' . $this->duration($cron['lastAge']) . ' ago';
        $add('cron', $cronStatus, implode('; ', $cronParts));

        $add('https', $this->state->runtime->https ? self::OK : ($local ? self::INFO : self::WARN), $this->state->runtime->https ? 'https' : 'http');

        $config = $this->configs->get();
        $mailActive = (int) $config->mail_active === 1;
        $mailMethods = [0 => 'mail()', 1 => 'sendmail', 2 => 'SMTP'];
        $mailMethod = $mailMethods[(int) $config->mail_use] ?? '?';
        if (!$mailActive) {
            $add('mail', self::WARN, 'off');
        } elseif ((int) $config->mail_use === 2 && trim((string) $config->smtp_host) === '') {
            $add('mail', self::FAIL, $mailMethod . ', no host');
        } else {
            $add('mail', self::OK, $mailMethod);
        }

        $scheme = $sessionDsn === '' ? 'file' : (string) (parse_url($sessionDsn, PHP_URL_SCHEME) ?: 'custom');
        $add('session', self::OK, $scheme);

        return $checks;
    }

    // ------------------------------------------------------------------------------ server

    /**
     * @return array<string, mixed>
     */
    public function server(): array
    {
        $root = $this->state->runtime->rootPath;

        $cpuCores = null;
        $cpuModel = null;
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuinfo) && $cpuinfo !== '') {
            $cpuCores = preg_match_all('/^processor\s*:/m', $cpuinfo) ?: null;
            if (preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $m)) {
                $cpuModel = trim($m[1]);
            }
        }

        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
        $uptime = null;
        $uptimeRaw = @file_get_contents('/proc/uptime');
        if (is_string($uptimeRaw) && preg_match('/^(\d+)/', $uptimeRaw, $m)) {
            $uptime = $this->duration((int) $m[1]);
        }

        $memory = null;
        $meminfo = @file_get_contents('/proc/meminfo');
        if (is_string($meminfo) && preg_match('/^MemTotal:\s+(\d+) kB/m', $meminfo, $t) && preg_match('/^MemAvailable:\s+(\d+) kB/m', $meminfo, $a)) {
            $total = (int) $t[1] * 1024;
            $avail = (int) $a[1] * 1024;
            $memory = ['total' => self::bytes($total), 'free' => self::bytes($avail), 'usedPct' => $total > 0 ? (int) round(($total - $avail) / $total * 100) : 0];
        }

        $diskTotal = @disk_total_space($root);
        $diskFree = @disk_free_space($root);
        $disk = null;
        if ($diskTotal !== false && $diskFree !== false && $diskTotal > 0) {
            $disk = ['total' => self::bytes($diskTotal), 'free' => self::bytes($diskFree), 'usedPct' => (int) round(($diskTotal - $diskFree) / $diskTotal * 100)];
        }

        $iniRealpath = (string) ini_get('realpath_cache_size');

        return [
            'os' => php_uname('s') . ' ' . php_uname('r') . ' (' . php_uname('m') . ')',
            'hostname' => gethostname() ?: null,
            'webserver' => (string) ($this->state->requestServer['SERVER_SOFTWARE'] ?? ''),
            'sapi' => PHP_SAPI,
            'php' => PHP_VERSION,
            'ini' => [
                'memory_limit' => (string) ini_get('memory_limit'),
                'max_execution_time' => (string) ini_get('max_execution_time'),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size' => (string) ini_get('post_max_size'),
                'realpath_cache_size' => $iniRealpath . ' / ' . self::bytes((int) realpath_cache_size()) . ' used',
            ],
            'cpuCores' => $cpuCores,
            'cpuModel' => $cpuModel,
            'load' => is_array($load) ? array_map(static fn(float $v): string => number_format($v, 2), $load) : null,
            'uptime' => $uptime,
            'memory' => $memory,
            'disk' => $disk,
            'peak' => self::bytes(memory_get_peak_usage(true)),
            'extensions' => $this->extensions(),
        ];
    }

    /**
     * @return list<array{name: string, loaded: bool, required: bool}>
     */
    private function extensions(): array
    {
        $list = [];
        foreach (self::REQUIRED_EXTENSIONS as $name) {
            $list[] = ['name' => $name, 'loaded' => extension_loaded($name), 'required' => true];
        }
        foreach (self::OPTIONAL_EXTENSIONS as $name) {
            $list[] = ['name' => $name, 'loaded' => self::extensionLoaded($name), 'required' => false];
        }

        return $list;
    }

    /**
     * @return array{used: string, total: string, free: string, usedPct: int, hitRate: string, scripts: string, wasted: string}|null null when OPcache is off or not queryable
     */
    private function opcache(): ?array
    {
        if (!function_exists('opcache_get_status')) {
            return null;
        }
        $status = @opcache_get_status(false);
        if (!is_array($status) || empty($status['opcache_enabled'])) {
            return null;
        }
        $used = (int) ($status['memory_usage']['used_memory'] ?? 0);
        $free = (int) ($status['memory_usage']['free_memory'] ?? 0);
        $wasted = (int) ($status['memory_usage']['wasted_memory'] ?? 0);
        $total = $used + $free + $wasted;

        return [
            'used' => self::bytes($used),
            'free' => self::bytes($free),
            'total' => self::bytes($total),
            'usedPct' => $total > 0 ? (int) round(($used + $wasted) / $total * 100) : 0,
            'hitRate' => number_format((float) ($status['opcache_statistics']['opcache_hit_rate'] ?? 0), 1),
            'scripts' => (string) (int) ($status['opcache_statistics']['num_cached_scripts'] ?? 0),
            'wasted' => number_format((float) ($status['memory_usage']['current_wasted_percentage'] ?? 0), 1),
        ];
    }

    /** @return array{used: string, free: string, total: string, usedPct: int, hitRate: string, scripts: string, wasted: string}|null */
    public function opcacheStatus(): ?array
    {
        return $this->opcache();
    }

    // ------------------------------------------------------------------------------ database

    /**
     * @return array<string, mixed>
     */
    public function database(): array
    {
        $tables = $this->tableStats();
        $size = 0;
        foreach ($tables as $t) {
            $size += $t['data'] + $t['index'];
        }
        uasort($tables, static fn(array $a, array $b): int => ($b['data'] + $b['index']) <=> ($a['data'] + $a['index']));
        $top = [];
        foreach (array_slice($tables, 0, 10, true) as $name => $t) {
            $top[] = ['name' => $name, 'rows' => $t['rows'], 'data' => self::bytes($t['data']), 'index' => self::bytes($t['index']), 'total' => self::bytes($t['data'] + $t['index'])];
        }

        $status = $this->show("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Questions','Slow_queries')");
        $vars = $this->show("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('max_connections','innodb_buffer_pool_size')");
        $uptime = isset($status['Uptime']) ? (int) $status['Uptime'] : null;
        $migrations = $this->migrations();

        return [
            'server' => (string) $this->db->selectSingle('SELECT VERSION() AS v', [], 'v'),
            'client' => (string) $this->db->getClientVersion(),
            'schema' => $this->db->getDatabaseName(),
            'size' => $tables === [] ? null : self::bytes($size),
            'tableCount' => $tables === [] ? null : count($tables),
            'top' => $top,
            'dbVersion' => $migrations['latest'],
            'pending' => $migrations['pending'],
            'uptime' => $uptime !== null ? $this->duration($uptime) : null,
            'threads' => isset($status['Threads_connected']) ? (int) $status['Threads_connected'] : null,
            'qps' => $uptime !== null && $uptime > 0 && isset($status['Questions']) ? number_format((int) $status['Questions'] / $uptime, 1) : null,
            'questions' => isset($status['Questions']) ? (int) $status['Questions'] : null,
            'slow' => isset($status['Slow_queries']) ? (int) $status['Slow_queries'] : null,
            'maxConnections' => isset($vars['max_connections']) ? (int) $vars['max_connections'] : null,
            'bufferPool' => isset($vars['innodb_buffer_pool_size']) ? self::bytes((int) $vars['innodb_buffer_pool_size']) : null,
        ];
    }

    /**
     * Pending migration files vs. the applied revisions (table system). Read only; the real runner
     * is Maintenance::listMigrations().
     *
     * @return array{latest: string|null, pending: int}
     */
    public function migrations(): array
    {
        $applied = [];
        $latest = null;
        try {
            foreach ($this->db->select('SELECT dbVersion FROM %%SYSTEM%%;') as $row) {
                $applied[(string) (int) $row['dbVersion']] = true;
                $latest = max((int) $row['dbVersion'], (int) $latest);
            }
        } catch (\Throwable) {
            return ['latest' => null, 'pending' => 0];
        }

        $pending = 0;
        foreach (glob($this->state->runtime->rootPath . 'install/migrations/migration_*') ?: [] as $file) {
            if (preg_match('/^migration_(\d+)\.(?:sql|php)$/', basename($file), $m) && !isset($applied[(string) (int) $m[1]])) {
                ++$pending;
            }
        }

        return ['latest' => $latest === null ? null : (string) $latest, 'pending' => $pending];
    }

    /**
     * information_schema once per request (estimates for InnoDB row counts).
     *
     * @return array<string, array{rows: int, data: int, index: int}>
     */
    private function tableStats(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }
        $this->tables = [];
        try {
            $rows = $this->db->select(
                'SELECT TABLE_NAME AS n, TABLE_ROWS AS r, DATA_LENGTH AS d, INDEX_LENGTH AS i FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = \'BASE TABLE\';',
                [':schema' => $this->db->getDatabaseName()],
            );
            foreach ($rows as $row) {
                $this->tables[(string) $row['n']] = ['rows' => (int) $row['r'], 'data' => (int) $row['d'], 'index' => (int) $row['i']];
            }
        } catch (\Throwable) {
            // no privileges: leave empty
        }

        return $this->tables;
    }

    /**
     * SHOW statements as name => value; empty when not permitted.
     *
     * @return array<string, string>
     */
    private function show(string $query): array
    {
        try {
            $result = [];
            foreach ($this->db->nativeQuery($query) ?: [] as $row) {
                $result[(string) $row['Variable_name']] = (string) $row['Value'];
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    // ------------------------------------------------------------------------------ cron

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
        $tables = $this->tableStats();
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
    private function registrationsPerDay(int $universe, string $timezone): array
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
    private function perUniverse(): array
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

    /**
     * @return array<string, mixed>
     */
    public function logs(string $environment): array
    {
        $root = $this->state->runtime->rootPath;
        $gameLog = $this->scanLog($root . 'var/log/game.log', $this->state->runtime->timestamp);
        $symfonyLog = $root . 'var/log/' . $environment . '.log';
        $phpLog = (string) ini_get('error_log');

        $phpLines = null;
        $phpSize = null;
        if ($phpLog !== '' && @is_file($phpLog) && @is_readable($phpLog)) {
            $phpSize = (int) @filesize($phpLog);
            if (realpath($phpLog) !== realpath($root . 'var/log/game.log')) {
                $scan = $this->scanLog($phpLog, $this->state->runtime->timestamp);
                $phpLines = $scan['lines'];
            }
        }

        return [
            'game' => $gameLog,
            'symfonyFile' => 'var/log/' . $environment . '.log',
            'symfonySize' => @is_file($symfonyLog) ? self::bytes((int) @filesize($symfonyLog)) : null,
            'phpLogPath' => $phpLog !== '' ? $this->relativePath($phpLog) : null,
            'phpLogEnabled' => (bool) ini_get('log_errors'),
            'phpLogSize' => $phpSize !== null ? self::bytes($phpSize) : null,
            'phpLogLines' => $phpLines,
            'dirs' => [
                'var/cache' => $this->dirSize($root . 'var/cache'),
                'var/game' => $this->dirSize($root . 'var/game'),
                'var/log' => $this->dirSize($root . 'var/log'),
            ],
        ];
    }

    /**
     * Reads only the tail of a log: error and warning entries of the last 24 hours and the last
     * five error lines. Timestamps are the "[15-Nov-2026 19:01:44 Europe/Berlin]" prefix of PHP and
     * the game error writer.
     *
     * @return array{path: string, size: string|null, scanned: string|null, errors24: int|null, warnings24: int|null, last: list<string>, lines: int|null}
     */
    private function scanLog(string $file, int $now): array
    {
        $out = ['path' => $this->relativePath($file), 'size' => null, 'scanned' => null, 'errors24' => null, 'warnings24' => null, 'last' => [], 'lines' => null];
        if (!@is_file($file) || !@is_readable($file)) {
            return $out;
        }
        $size = (int) @filesize($file);
        $out['size'] = self::bytes($size);

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return $out;
        }
        $offset = max(0, $size - self::LOG_TAIL_BYTES);
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $chunk = (string) stream_get_contents($handle, self::LOG_TAIL_BYTES);
        fclose($handle);
        if ($offset > 0) {
            $cut = strpos($chunk, "\n");
            $chunk = $cut === false ? '' : substr($chunk, $cut + 1); // drop the partial first line
        }
        $out['scanned'] = self::bytes(strlen($chunk));
        $out['lines'] = substr_count($chunk, "\n");

        $since = $now - 86400;
        $errors = 0;
        $warnings = 0;
        $last = [];
        $errorPattern = '/\[(?:error|critical|alert|emergency)\]|PHP (?:Fatal error|Parse error)|\bUncaught\b|^\[[^\]]+\]\s+(?:Fatal error|Error|Exception)\b/i';
        $warningPattern = '/\[warning\]|PHP (?:Warning|Notice|Deprecated)/i';
        foreach (preg_split('/\r?\n/', $chunk) ?: [] as $line) {
            if ($line === '' || $line[0] !== '[') {
                continue;
            }
            $isError = preg_match($errorPattern, $line) === 1;
            $isWarning = !$isError && preg_match($warningPattern, $line) === 1;
            if (!$isError && !$isWarning) {
                continue;
            }
            if (!preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([\w\/+-]+))?\]/', $line, $m)) {
                continue;
            }
            try {
                $time = (new \DateTimeImmutable($m[1], new \DateTimeZone($m[2] ?? date_default_timezone_get())))->getTimestamp();
            } catch (\Throwable) {
                continue;
            }
            if ($time < $since) {
                continue;
            }
            if ($isError) {
                ++$errors;
                $last[] = mb_strimwidth($line, 0, 300, '…');
                if (count($last) > 5) {
                    array_shift($last);
                }
            } else {
                ++$warnings;
            }
        }
        $out['errors24'] = $errors;
        $out['warnings24'] = $warnings;
        $out['last'] = array_reverse($last);

        return $out;
    }

    // ------------------------------------------------------------------------------ helpers

    private function relativePath(string $path): string
    {
        $root = rtrim($this->state->runtime->rootPath, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * Directory size, bounded (file count and time) so a huge cache cannot slow the page down.
     *
     * @return array{size: string|null, files: int, partial: bool}
     */
    private function dirSize(string $dir): array
    {
        if (!is_dir($dir)) {
            return ['size' => null, 'files' => 0, 'partial' => false];
        }
        $start = microtime(true);
        $size = 0;
        $files = 0;
        $partial = false;
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += (int) $file->getSize();
                    ++$files;
                }
                if ($files >= 30000 || microtime(true) - $start > 0.15) {
                    $partial = true;
                    break;
                }
            }
        } catch (\Throwable) {
            $partial = true;
        }

        return ['size' => self::bytes($size), 'files' => $files, 'partial' => $partial];
    }

    private static function extensionLoaded(string $name): bool
    {
        return extension_loaded($name) || ($name === 'opcache' && extension_loaded('Zend OPcache'));
    }

    private function isLocalHost(string $host): bool
    {
        $host = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);

        return $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]'
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.ddev.site')
            || str_ends_with($host, '.test') || str_ends_with($host, '.local');
    }

    private function duration(int $seconds): string
    {
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($d > 0) {
            return $d . 'd ' . $h . 'h';
        }
        if ($h > 0) {
            return $h . 'h ' . $m . 'm';
        }
        if ($m > 0) {
            return $m . 'm';
        }

        return $seconds . 's';
    }

    public static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            ++$i;
        }

        return ($i === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$i];
    }
}
