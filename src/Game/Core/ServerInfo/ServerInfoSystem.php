<?php

namespace App\Game\Core\ServerInfo;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Runtime\SafeFile;

/**
 * PHP and server section: runtime, extensions, opcache and system load.
 */
class ServerInfoSystem
{
    /** required PHP extensions */
    public const REQUIRED_EXTENSIONS = ['pdo_mysql', 'intl', 'mbstring', 'gd', 'curl', 'json', 'ctype'];
    public const OPTIONAL_EXTENSIONS = ['opcache', 'ffi', 'ds', 'redis', 'imap'];

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function server(): array
    {
        $root = $this->state->runtime->rootPath;

        $cpuCores = null;
        $cpuModel = null;
        $cpuinfo = SafeFile::read('/proc/cpuinfo');
        if (is_string($cpuinfo) && $cpuinfo !== '') {
            $cpuCores = preg_match_all('/^processor\s*:/m', $cpuinfo) ?: null;
            if (preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $m)) {
                $cpuModel = trim($m[1]);
            }
        }

        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        $uptime = null;
        $uptimeRaw = SafeFile::read('/proc/uptime');
        if (is_string($uptimeRaw) && preg_match('/^(\d+)/', $uptimeRaw, $m)) {
            $uptime = ServerInfoFormat::duration((int) $m[1]);
        }

        $memory = null;
        $meminfo = SafeFile::read('/proc/meminfo');
        if (is_string($meminfo) && preg_match('/^MemTotal:\s+(\d+) kB/m', $meminfo, $t) && preg_match('/^MemAvailable:\s+(\d+) kB/m', $meminfo, $a)) {
            $total = (int) $t[1] * 1024;
            $avail = (int) $a[1] * 1024;
            $memory = ['total' => ServerInfoFormat::bytes($total), 'free' => ServerInfoFormat::bytes($avail), 'usedPct' => $total > 0 ? (int) round(($total - $avail) / $total * 100) : 0];
        }

        $diskTotal = is_dir($root) ? disk_total_space($root) : false;
        $diskFree = is_dir($root) ? disk_free_space($root) : false;
        $disk = null;
        if ($diskTotal !== false && $diskFree !== false && $diskTotal > 0) {
            $disk = ['total' => ServerInfoFormat::bytes($diskTotal), 'free' => ServerInfoFormat::bytes($diskFree), 'usedPct' => (int) round(($diskTotal - $diskFree) / $diskTotal * 100)];
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
                'realpath_cache_size' => $iniRealpath . ' / ' . ServerInfoFormat::bytes((int) realpath_cache_size()) . ' used',
            ],
            'cpuCores' => $cpuCores,
            'cpuModel' => $cpuModel,
            'load' => is_array($load) ? array_map(static fn(float $v): string => number_format($v, 2), $load) : null,
            'uptime' => $uptime,
            'memory' => $memory,
            'disk' => $disk,
            'peak' => ServerInfoFormat::bytes(memory_get_peak_usage(true)),
            'extensions' => $this->extensions(),
        ];
    }

    /**
     * @return list<array{name: string, loaded: bool, required: bool}>
     */
    public function extensions(): array
    {
        $list = [];
        foreach (self::REQUIRED_EXTENSIONS as $name) {
            $list[] = ['name' => $name, 'loaded' => extension_loaded($name), 'required' => true];
        }
        foreach (self::OPTIONAL_EXTENSIONS as $name) {
            $list[] = ['name' => $name, 'loaded' => ServerInfoFormat::extensionLoaded($name), 'required' => false];
        }

        return $list;
    }

    /**
     * @return array{used: string, total: string, free: string, usedPct: int, hitRate: string, scripts: string, wasted: string}|null null when OPcache is off or not queryable
     */
    public function opcache(): ?array
    {
        if (!function_exists('opcache_get_status')) {
            return null;
        }
        $status = opcache_get_status(false);
        if (!is_array($status) || empty($status['opcache_enabled'])) {
            return null;
        }
        $used = (int) ($status['memory_usage']['used_memory'] ?? 0);
        $free = (int) ($status['memory_usage']['free_memory'] ?? 0);
        $wasted = (int) ($status['memory_usage']['wasted_memory'] ?? 0);
        $total = $used + $free + $wasted;

        return [
            'used' => ServerInfoFormat::bytes($used),
            'free' => ServerInfoFormat::bytes($free),
            'total' => ServerInfoFormat::bytes($total),
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
}
