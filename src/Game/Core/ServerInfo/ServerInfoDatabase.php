<?php

namespace App\Game\Core\ServerInfo;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Runtime\SafeFile;

/**
 * Database section: server version and size, tables, applied migrations.
 */
class ServerInfoDatabase
{
    /** @var array<string, array{rows: int, data: int, index: int}>|null */
    private ?array $tables = null;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

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
            $top[] = ['name' => $name, 'rows' => $t['rows'], 'data' => ServerInfoFormat::bytes($t['data']), 'index' => ServerInfoFormat::bytes($t['index']), 'total' => ServerInfoFormat::bytes($t['data'] + $t['index'])];
        }

        $status = $this->show("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Questions','Slow_queries')");
        $vars = $this->show("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('max_connections','innodb_buffer_pool_size')");
        $uptime = isset($status['Uptime']) ? (int) $status['Uptime'] : null;
        $migrations = $this->migrations();

        return [
            'server' => (string) $this->db->selectSingle('SELECT VERSION() AS v', [], 'v'),
            'client' => (string) $this->db->getClientVersion(),
            'schema' => $this->db->getDatabaseName(),
            'size' => $tables === [] ? null : ServerInfoFormat::bytes($size),
            'tableCount' => $tables === [] ? null : count($tables),
            'top' => $top,
            'dbVersion' => $migrations['latest'],
            'pending' => $migrations['pending'],
            'uptime' => $uptime !== null ? ServerInfoFormat::duration($uptime) : null,
            'threads' => isset($status['Threads_connected']) ? (int) $status['Threads_connected'] : null,
            'qps' => $uptime !== null && $uptime > 0 && isset($status['Questions']) ? number_format((int) $status['Questions'] / $uptime, 1) : null,
            'questions' => isset($status['Questions']) ? (int) $status['Questions'] : null,
            'slow' => isset($status['Slow_queries']) ? (int) $status['Slow_queries'] : null,
            'maxConnections' => isset($vars['max_connections']) ? (int) $vars['max_connections'] : null,
            'bufferPool' => isset($vars['innodb_buffer_pool_size']) ? ServerInfoFormat::bytes((int) $vars['innodb_buffer_pool_size']) : null,
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
    public function tableStats(): array
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
    public function show(string $query): array
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
}
