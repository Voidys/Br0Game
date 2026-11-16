<?php

/**
 * Makes the planet position unique: replaces the plain index `universe` of the planets table with a UNIQUE index on
 * (universe, galaxy, system, planet, planet_type), so two colony fleets arriving in the same second can never create two
 * planets (or a planet and a moon) at one position. Destroyed planets keep their row (and so their position) until they are
 * cleaned up, exactly like before (PlanetPositions::isPositionFree counts them).
 *
 * Skipped with a warning (exit 0, nothing changed) when the table already holds duplicate positions: remove or move the
 * extra rows by hand and run the script again. Idempotent. Self-contained CLI script, like the other PHP migrations
 * (the upgrader copies it to the project root; `php install/migrations/migration_1794900600.php` works too).
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
    $table = '`' . str_replace('`', '', $database['tableprefix']) . 'planets`';

    $unique = $pdo->query("SHOW INDEX FROM $table WHERE Key_name = 'universe'")->fetchAll(PDO::FETCH_ASSOC);
    if ($unique !== [] && (int) $unique[0]['Non_unique'] === 0) {
        echo 'planets position index is already unique', PHP_EOL;
        exit(0);
    }

    $duplicates = $pdo->query(
        "SELECT `universe`, `galaxy`, `system`, `planet`, `planet_type`, COUNT(*) AS rows_at_position
        FROM $table GROUP BY `universe`, `galaxy`, `system`, `planet`, `planet_type` HAVING rows_at_position > 1",
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($duplicates !== []) {
        echo 'WARNING: planets position index NOT made unique, duplicate positions exist (universe:galaxy:system:planet type):', PHP_EOL;
        foreach ($duplicates as $row) {
            echo sprintf('  %d:%d:%d:%d type %d (%d rows)', $row['universe'], $row['galaxy'], $row['system'], $row['planet'], $row['planet_type'], $row['rows_at_position']), PHP_EOL;
        }
        echo 'Fix the duplicates and run this migration again.', PHP_EOL;
        exit(0);
    }

    $pdo->exec(
        "ALTER TABLE $table DROP INDEX `universe`, ADD UNIQUE KEY `universe` (`universe`, `galaxy`, `system`, `planet`, `planet_type`)",
    );
    echo 'planets position index is now unique', PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
