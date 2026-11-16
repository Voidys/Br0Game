<?php

namespace App\Game\Core\ServerInfo;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Runtime\SafeFile;

/**
 * Logs and caches section: size and recent errors of the log files, cache sizes.
 */
class ServerInfoLogs
{
    /** the log tail that is scanned (bytes), never the whole file */
    private const LOG_TAIL_BYTES = 2097152;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

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
        if ($phpLog !== '' && is_file($phpLog) && is_readable($phpLog)) {
            $phpSize = (int) filesize($phpLog);
            if (realpath($phpLog) !== realpath($root . 'var/log/game.log')) {
                $scan = $this->scanLog($phpLog, $this->state->runtime->timestamp);
                $phpLines = $scan['lines'];
            }
        }

        return [
            'game' => $gameLog,
            'symfonyFile' => 'var/log/' . $environment . '.log',
            'symfonySize' => is_file($symfonyLog) ? ServerInfoFormat::bytes((int) filesize($symfonyLog)) : null,
            'phpLogPath' => $phpLog !== '' ? $this->relativePath($phpLog) : null,
            'phpLogEnabled' => (bool) ini_get('log_errors'),
            'phpLogSize' => $phpSize !== null ? ServerInfoFormat::bytes($phpSize) : null,
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
    public function scanLog(string $file, int $now): array
    {
        $out = ['path' => $this->relativePath($file), 'size' => null, 'scanned' => null, 'errors24' => null, 'warnings24' => null, 'last' => [], 'lines' => null];
        if (!is_file($file) || !is_readable($file)) {
            return $out;
        }
        $size = (int) filesize($file);
        $out['size'] = ServerInfoFormat::bytes($size);

        $handle = fopen($file, 'rb');
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
        $out['scanned'] = ServerInfoFormat::bytes(strlen($chunk));
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

    /**
     * Path relative to the project root (unchanged outside it), so the admin server info does not reveal absolute paths.
     */
    public function relativePath(string $path): string
    {
        $root = rtrim($this->state->runtime->rootPath, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * Directory size, bounded (file count and time) so a huge cache cannot slow the page down.
     *
     * @return array{size: string|null, files: int, partial: bool}
     */
    public function dirSize(string $dir): array
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

        return ['size' => ServerInfoFormat::bytes($size), 'files' => $files, 'partial' => $partial];
    }
}
