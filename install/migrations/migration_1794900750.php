<?php

/**
 * Makes the e-mail address of an account unique: adds a UNIQUE index `uniq_accounts_email` on accounts.email next to the
 * plain index `idx_userids`, so two parallel verification links (or registrations) can never create two accounts with one
 * address. The registration and verification code additionally serialise with a lock; the index is the last line of defence.
 *
 * Skipped with a warning (exit 0, nothing changed) when the table already holds duplicate addresses: merge or change the
 * extra accounts by hand (admin area) and run the script again. Idempotent. Self-contained CLI script, like the other PHP
 * migrations (`php install/migrations/migration_1794900750.php` works too).
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
    $table = '`' . str_replace('`', '', $database['tableprefix']) . 'accounts`';

    if ($pdo->query("SHOW INDEX FROM $table WHERE Key_name = 'uniq_accounts_email'")->fetchAll() !== []) {
        echo 'accounts.email is already unique', PHP_EOL;
        exit(0);
    }

    $duplicates = $pdo->query(
        "SELECT `email`, COUNT(*) AS accounts_with_email, GROUP_CONCAT(`id` ORDER BY `id`) AS ids
        FROM $table GROUP BY `email` HAVING accounts_with_email > 1",
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($duplicates !== []) {
        echo 'WARNING: accounts.email NOT made unique, several accounts share an address (address: account ids):', PHP_EOL;
        foreach ($duplicates as $row) {
            echo sprintf('  %s: %s', $row['email'], $row['ids']), PHP_EOL;
        }
        echo 'Change or delete the extra accounts and run this migration again.', PHP_EOL;
        exit(0);
    }

    $pdo->exec("ALTER TABLE $table ADD UNIQUE KEY `uniq_accounts_email` (`email`)");
    echo 'accounts.email is now unique', PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
