<?php

namespace App\Game\Core;

/**
 * Applies the pending database migrations (install/migrations/migration_<revision>.{sql,php}) and records each applied
 * revision in `system`. Shared by the installer's upgrade mode and the admin maintenance page; the pending list comes
 * from Maintenance::listMigrations().
 */
class MigrationRunner
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Applies every pending migration in revision order and stops at the first failing one.
     * SQL migrations skip single failing queries (logged, reported as warnings, e.g. "column already exists");
     * a PHP migration fails on a non-zero exit code. Clears the game caches (including the migration caches) when
     * at least one migration was attempted.
     *
     * @return list<array{revision: string, file: string, ok: bool, message: string, warnings: list<string>}>
     */
    public function run(): array
    {
        set_time_limit(600);
        $versionSql = 'INSERT INTO %%SYSTEM%% VALUES (:dbVersion);';
        $results = [];

        foreach ($this->maintenance()->listMigrations() as $fileInfo) {
            $result = match ($fileInfo['fileExtension']) {
                'php' => $this->runPhp($fileInfo),
                'sql' => $this->runSql($fileInfo),
                default => null,
            };
            if ($result === null) {
                continue;
            }
            if ($result['ok']) {
                $this->db->insert($versionSql, [':dbVersion' => $fileInfo['fileRevision']]);
            }
            $results[] = $result;
            if (!$result['ok']) {
                break;
            }
        }

        if ($results !== []) {
            $this->maintenance()->clearCache();
        }

        return $results;
    }

    /**
     * Runs a PHP migration as a CLI script from the project root (copied there for the run, removed afterwards).
     *
     * @param array{fileName: string, fileRevision: mixed} $fileInfo
     * @return array{revision: string, file: string, ok: bool, message: string, warnings: list<string>}
     */
    private function runPhp(array $fileInfo): array
    {
        $root = $this->state->runtime->rootPath;
        $copy = $root . $fileInfo['fileName'];
        $output = [];
        $exitCode = 1;
        if (@copy($root . 'install/migrations/' . $fileInfo['fileName'], $copy)) {
            exec(
                'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINDIR . '/php') . ' '
                . escapeshellarg($fileInfo['fileName']) . ' 2>&1',
                $output,
                $exitCode,
            );
            @unlink($copy);
        } else {
            $output = ['Could not copy the migration to the project root (not writable).'];
        }

        return [
            'revision' => (string) $fileInfo['fileRevision'],
            'file' => $fileInfo['fileName'],
            'ok' => $exitCode === 0,
            'message' => implode("\n", $output),
            'warnings' => [],
        ];
    }

    /**
     * Runs a SQL migration (`%PREFIX%` replaced, queries split at `;\n`). A failing single query is logged and
     * skipped (this makes "ALTER ... IF NOT EXISTS"-style files idempotent) and reported as a warning.
     *
     * @param array{fileName: string, fileRevision: mixed} $fileInfo
     * @return array{revision: string, file: string, ok: bool, message: string, warnings: list<string>}
     */
    private function runSql(array $fileInfo): array
    {
        $warnings = [];
        $data = (string) file_get_contents($this->state->runtime->rootPath . 'install/migrations/' . $fileInfo['fileName']);
        try {
            $queries = array_filter(explode(";\n", str_replace('%PREFIX%', $this->db->getTablePrefix(), $data)));
            foreach ($queries as $query) {
                if (empty(trim($query))) {
                    error_log('Query: [' . $query . '] is empty. File: ' . $fileInfo['fileName']);
                    continue;
                }
                try {
                    $this->db->nativeQuery(trim($query));
                } catch (\Exception $e) {
                    error_log('Query: [' . $query . '] failed. Error: ' . $e->getMessage() . '. Skipped');
                    $warnings[] = trim($query) . ' => ' . $e->getMessage();
                }
            }
        } catch (\Exception $e) {
            return [
                'revision' => (string) $fileInfo['fileRevision'],
                'file' => $fileInfo['fileName'],
                'ok' => false,
                'message' => $e->getMessage(),
                'warnings' => $warnings,
            ];
        }

        return [
            'revision' => (string) $fileInfo['fileRevision'],
            'file' => $fileInfo['fileName'],
            'ok' => true,
            'message' => '',
            'warnings' => $warnings,
        ];
    }
}
