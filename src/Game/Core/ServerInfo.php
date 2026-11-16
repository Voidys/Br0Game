<?php

namespace App\Game\Core;

use App\Game\Core\ServerInfo\ServerInfoDatabase;
use App\Game\Core\ServerInfo\ServerInfoFormat;
use App\Game\Core\ServerInfo\ServerInfoGame;
use App\Game\Core\ServerInfo\ServerInfoLogs;
use App\Game\Core\ServerInfo\ServerInfoSystem;
use App\Runtime\SafeFile;

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

    private ?ServerInfoSystem $system = null;
    private ?ServerInfoDatabase $database = null;
    private ?ServerInfoGame $game = null;
    private ?ServerInfoLogs $logs = null;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

    private function systemInfo(): ServerInfoSystem
    {
        return $this->system ??= new ServerInfoSystem($this->db, $this->configs, $this->state);
    }

    private function databaseInfo(): ServerInfoDatabase
    {
        return $this->database ??= new ServerInfoDatabase($this->db, $this->configs, $this->state);
    }

    private function gameInfo(): ServerInfoGame
    {
        return $this->game ??= new ServerInfoGame($this->db, $this->configs, $this->state, $this->databaseInfo());
    }

    private function logInfo(): ServerInfoLogs
    {
        return $this->logs ??= new ServerInfoLogs($this->db, $this->configs, $this->state);
    }

    // ------------------------------------------------------------------------------ health

    /**
     * @return list<array{id: string, status: string, value: string}> in display order
     */
    public function checks(string $environment, bool $debug, string $sessionDsn, callable $formatDate): array
    {
        $root = $this->state->runtime->rootPath;
        $local = ServerInfoFormat::isLocalHost($this->state->runtime->httpHost);
        $checks = [];
        $add = static function (string $id, string $status, string $value) use (&$checks): void {
            $checks[] = ['id' => $id, 'status' => $status, 'value' => $value];
        };

        $this->addPhpChecks($add);
        $this->addEnvironmentChecks($add, $environment, $debug, $sessionDsn, $local);

        $migrations = $this->databaseInfo()->migrations();
        $add('migrations', $migrations['pending'] === 0 ? self::OK : self::FAIL, $migrations['pending'] === 0 ? (string) $migrations['latest'] : $migrations['pending'] . ' pending');

        $rustLauncher = $root . 'bin/battlerust';
        $rustLib = $root . 'bin/libbattlerust.so';
        $rustOk = function_exists('proc_open') && PHP_OS_FAMILY === 'Linux' && is_executable($rustLauncher) && is_file($rustLib);
        $add('battle_engine', $rustOk ? self::OK : self::WARN, $rustOk ? 'Rust' : 'PHP fallback');

        [$cronStatus, $cronValue] = $this->cronCheck($formatDate);
        $add('cron', $cronStatus, $cronValue);

        $add('https', $this->state->runtime->https ? self::OK : ($local ? self::INFO : self::WARN), $this->state->runtime->https ? 'https' : 'http');

        $this->addMailCheck($add);

        $scheme = $sessionDsn === '' ? 'file' : (string) (parse_url($sessionDsn, PHP_URL_SCHEME) ?: 'custom');
        $add('session', self::OK, $scheme);

        return $checks;
    }

    /**
     * PHP version, required and optional extensions and the opcache state.
     *
     * @param callable(string, string, string): void $add adds a check row (id, status, value)
     */
    private function addPhpChecks(callable $add): void
    {
        $add('php', version_compare(PHP_VERSION, '8.4.0', '>=') ? self::OK : self::FAIL, PHP_VERSION);

        $missing = array_values(array_filter(ServerInfoSystem::REQUIRED_EXTENSIONS, static fn(string $e): bool => !extension_loaded($e)));
        $add('ext_required', $missing === [] ? self::OK : self::FAIL, $missing === [] ? (string) count(ServerInfoSystem::REQUIRED_EXTENSIONS) . ' / ' . count(ServerInfoSystem::REQUIRED_EXTENSIONS) : implode(', ', $missing));

        $missingOptional = array_values(array_filter(ServerInfoSystem::OPTIONAL_EXTENSIONS, static fn(string $e): bool => !ServerInfoFormat::extensionLoaded($e)));
        $add('ext_optional', $missingOptional === [] ? self::OK : self::INFO, $missingOptional === [] ? 'all loaded' : implode(', ', $missingOptional));

        $opcache = $this->systemInfo()->opcache();
        if ($opcache === null) {
            $add('opcache', self::WARN, 'off');
        } else {
            $add('opcache', self::OK, $opcache['used'] . ' / ' . $opcache['total'] . ', ' . $opcache['hitRate'] . ' %, ' . $opcache['scripts'] . ' scripts');
        }
    }

    /**
     * Environment (prod/debug), display_errors, writable directories (var/* and the news uploads) and the install tool
     * marker. Unproblematic on a local host: findings there are only informational.
     *
     * @param callable(string, string, string): void $add adds a check row (id, status, value)
     */
    private function addEnvironmentChecks(callable $add, string $environment, bool $debug, string $sessionDsn, bool $local): void
    {
        $root = $this->state->runtime->rootPath;
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
    }

    /**
     * Health of the cron: failing when jobs are locked or the last run is older than 15 minutes, warning when jobs are
     * overdue, the cron never ran or the last run is older than 5 minutes.
     *
     * @return array{0: string, 1: string} status, description (locked jobs, overdue jobs, time of the last run)
     */
    private function cronCheck(callable $formatDate): array
    {
        $cron = $this->gameInfo()->cron($formatDate);
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
        $cronParts[] = $cron['lastAge'] === null ? 'never run' : 'last run ' . ServerInfoFormat::duration($cron['lastAge']) . ' ago';

        return [$cronStatus, implode('; ', $cronParts)];
    }

    /**
     * Mail: off is a warning, SMTP without host fails.
     *
     * @param callable(string, string, string): void $add adds a check row (id, status, value)
     */
    private function addMailCheck(callable $add): void
    {
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
    }

    // ------------------------------------------------------------------------------ server

    /**
     * @return array<string, mixed>
     */
    public function server(): array
    {
        return $this->systemInfo()->server();
    }

    /** @return array{used: string, free: string, total: string, usedPct: int, hitRate: string, scripts: string, wasted: string}|null */
    public function opcacheStatus(): ?array
    {
        return $this->systemInfo()->opcacheStatus();
    }

    /**
     * @return array<string, mixed>
     */
    public function database(): array
    {
        return $this->databaseInfo()->database();
    }

    /**
     * Pending migration files vs. the applied revisions (table system). Read only; the real runner
     * is Maintenance::listMigrations().
     *
     * @return array{latest: string|null, pending: int}
     */
    public function migrations(): array
    {
        return $this->databaseInfo()->migrations();
    }

    /**
     * @return array{jobs: int, stuck: list<string>, overdue: list<string>, lastAge: int|null, lastRun: string|null}
     */
    public function cron(callable $formatDate): array
    {
        return $this->gameInfo()->cron($formatDate);
    }

    /**
     * Statistics of one universe plus a per universe overview. Indexed counts only; large tables
     * use the information_schema estimate (marked as such by the template).
     *
     * @return array<string, mixed>
     */
    public function game(int $universe, callable $formatDate, string $timezone): array
    {
        return $this->gameInfo()->game($universe, $formatDate, $timezone);
    }

    /**
     * @return array<string, mixed>
     */
    public function logs(string $environment): array
    {
        return $this->logInfo()->logs($environment);
    }

    /**
     * Size with binary units, see ServerInfoFormat::bytes().
     */
    public static function bytes(int|float $bytes): string
    {
        return ServerInfoFormat::bytes($bytes);
    }
}
