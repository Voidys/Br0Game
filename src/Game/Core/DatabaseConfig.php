<?php

namespace App\Game\Core;

/**
 * Database settings in the legacy $database array format (host, port, user, userpw,
 * databasename, tableprefix, migrationpw).
 *
 * The Symfony environment is the single source when it is configured (DATABASE_URL and
 * TABLE_PREFIX in .env / .env.local / real env vars). config/game.php is the fallback,
 * e.g. while the installer runs or for CLI migration scripts outside Symfony; it also still
 * provides migrationpw.
 */
final class DatabaseConfig
{
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'default::DATABASE_URL')]
        private ?string $databaseUrl = null,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'default::TABLE_PREFIX')]
        private ?string $tablePrefix = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function get(): array
    {
        $database = [];
        $configFile = rtrim($this->projectDir, '/') . '/config/game.php';
        if (is_file($configFile) && filesize($configFile) > 0) {
            require $configFile;
        }

        $url = $this->databaseUrl;
        $parts = is_string($url) && $url !== '' ? parse_url($url) : false;
        if (is_array($parts) && isset($parts['host'])) {
            $database['host'] = $parts['host'];
            $database['port'] = (string) ($parts['port'] ?? 3306);
            $database['user'] = rawurldecode($parts['user'] ?? '');
            $database['userpw'] = rawurldecode($parts['pass'] ?? '');
            $database['databasename'] = ltrim($parts['path'] ?? '', '/');
        }

        $prefix = $this->tablePrefix;
        if (is_string($prefix) && $prefix !== '') {
            $database['tableprefix'] = $prefix;
        }

        return $database;
    }

}
