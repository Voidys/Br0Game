<?php

/**
 * Converts the structured database columns (build/tech/hangar queues, repair orders, rights, admin log data,
 * PHP-engine battle reports) from PHP serialize() to JSON. See App\Game\Core\StoredDataMigrator.
 *
 * Self-contained CLI script: the web upgrader (Installer, "doupgrade") copies it to the project root and runs it
 * with the PHP binary; it can also be run by hand from the project root: `php migration_1794900200.php` (copy it
 * there first, or run `php install/migrations/migration_1794900200.php`; both work). Idempotent.
 */

$root = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : dirname(__DIR__, 2);

require_once $root . '/vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv($root . '/.env');

$database = (new App\Game\Core\DatabaseConfig($root, $_SERVER['DATABASE_URL'] ?? null, $_SERVER['TABLE_PREFIX'] ?? null))->get();

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $database['host'], $database['port'] ?? '3306', $database['databasename']),
        $database['user'],
        $database['userpw'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );

    $results = (new App\Game\Core\StoredDataMigrator($pdo, $database['tableprefix']))->run(
        static function (string $line): void {
            echo $line, PHP_EOL;
        },
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
