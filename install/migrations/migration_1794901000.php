<?php

/**
 * Makes the player name unique per universe: adds a UNIQUE index `uniq_universe_username` on (universe, username) to the
 * users table, so two players can never end up with the same name (rename races, registration races); the application
 * checks the name first and turns a duplicate-key error of this index into the usual "name exists" message.
 *
 * Skipped with a warning (exit 0, nothing changed) when the table already holds duplicate names: rename the extra
 * players by hand and run the script again. Idempotent. Self-contained CLI script, like the other PHP migrations
 * (`php install/migrations/migration_1794901000.php` works too).
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
    $table = '`' . str_replace('`', '', $database['tableprefix']) . 'users`';

    $existing = $pdo->query("SHOW INDEX FROM $table WHERE Key_name = 'uniq_universe_username'")->fetchAll(PDO::FETCH_ASSOC);
    if ($existing !== []) {
        echo 'users name index is already unique', PHP_EOL;
        exit(0);
    }

    $duplicates = $pdo->query(
        "SELECT `universe`, `username`, COUNT(*) AS rows_with_name FROM $table GROUP BY `universe`, `username` HAVING rows_with_name > 1",
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($duplicates !== []) {
        echo 'WARNING: users name index NOT made unique, duplicate names exist (universe: name):', PHP_EOL;
        foreach ($duplicates as $row) {
            echo sprintf('  %d: %s (%d players)', $row['universe'], $row['username'], $row['rows_with_name']), PHP_EOL;
        }
        echo 'Rename the duplicates and run this migration again.', PHP_EOL;
        exit(0);
    }

    $pdo->exec("ALTER TABLE $table ADD UNIQUE KEY `uniq_universe_username` (`universe`, `username`)");
    echo 'users name index is now unique', PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
