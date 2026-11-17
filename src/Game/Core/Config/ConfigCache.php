<?php

namespace App\Game\Core\Config;

use App\Runtime\SafeFile;

/**
 * File cache (opcache friendly PHP array) of the parsed game configuration: the converted and raw rows of `config`,
 * `config_universe` and the module states, so a request does not run three queries and the transforms.
 *
 * Validity: the entry carries a generation stamp (config.stamp) and a fingerprint (database, table prefix, schema
 * code, module list). Every writer of the config tables calls invalidate(), which only writes a new random stamp. The
 * reader takes the stamp BEFORE it reads the database and stores the entry under that stamp, so a request that read
 * old rows while someone saved cannot publish them afterwards: its stamp no longer matches. Hand-edited rows or a
 * restored database need the stamp bumped (delete var/game/ or run the admin "clear cache").
 */
final class ConfigCache
{
    private const FILE = 'cache.config.php';
    private const STAMP = 'config.stamp';

    public function __construct(
        private readonly string $dir,
        private readonly bool $enabled = true,
    ) {}

    /** Current generation stamp ('' when none was written yet or the cache is off). */
    public function stamp(): string
    {
        if (!$this->enabled) {
            return '';
        }

        return trim((string) SafeFile::read($this->dir . self::STAMP, 64));
    }

    /**
     * @return array<string, mixed>|null the cached data when stamp and fingerprint match, else null
     */
    public function load(string $stamp, string $fingerprint): ?array
    {
        if (!$this->enabled) {
            return null;
        }
        $file = $this->dir . self::FILE;
        if (!is_file($file)) {
            return null;
        }
        $entry = include $file;

        return is_array($entry) && ($entry['stamp'] ?? null) === $stamp && ($entry['fingerprint'] ?? null) === $fingerprint
            && is_array($entry['data'] ?? null) ? $entry['data'] : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function store(string $stamp, string $fingerprint, array $data): void
    {
        if (!$this->enabled) {
            return;
        }
        SafeFile::write($this->dir . self::FILE, '<?php return ' . var_export(['stamp' => $stamp, 'fingerprint' => $fingerprint, 'data' => $data], true) . ';');
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->dir . self::FILE, true);
        }
    }

    /** Declares every cached entry stale (call after any write to the config tables). */
    public function invalidate(): void
    {
        if (!$this->enabled) {
            return;
        }
        SafeFile::write($this->dir . self::STAMP, bin2hex(random_bytes(8)));
    }
}
