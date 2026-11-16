<?php

/**
 * Masks secrets in old admin log rows. The basic config form logged the old and new value of every field (log mode 3), so
 * `smtp_pass`, `recaptcha_priv_key` and the Discord hooks (`discord_logs_hook`, `discord_exceptions_hook`,
 * `discord_tickets_hook`) sit in clear text in `log.data`. Each non-empty value is replaced by the hint the config form
 * stores now (ConfigFieldProcessor::secretHint(): bullets plus the last 4 characters of a value longer than 8 characters).
 * Idempotent (a hint is masked again to the same hint). Self-contained CLI script, like the other PHP migrations
 * (the upgrader copies it to the project root; `php install/migrations/migration_1794901200.php` works too).
 */

$root = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : dirname(__DIR__, 2);

require_once $root . '/vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv($root . '/.env');

$database = (new App\Game\Core\DatabaseConfig($root, $_SERVER['DATABASE_URL'] ?? null, $_SERVER['TABLE_PREFIX'] ?? null))->get();

// the keys flagged `secret` in ConfigFieldSchema
$secretKeys = ['smtp_pass', 'recaptcha_priv_key', 'discord_logs_hook', 'discord_exceptions_hook', 'discord_tickets_hook'];

$hint = static function (string $value): string {
    if ($value === '' || str_starts_with($value, '••••')) {
        return $value;
    }

    return '••••' . (mb_strlen($value) > 8 ? mb_substr($value, -4) : '');
};

/** masks the secret keys of one logged array (old or new values) */
$mask = static function (mixed $values) use ($secretKeys, $hint): mixed {
    if (!is_array($values)) {
        return $values;
    }
    foreach ($secretKeys as $key) {
        if (isset($values[$key]) && is_scalar($values[$key])) {
            $values[$key] = $hint((string) $values[$key]);
        }
    }

    return $values;
};

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $database['host'], $database['port'] ?? '3306', $database['databasename']),
        $database['user'],
        $database['userpw'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $table = '`' . str_replace('`', '', $database['tableprefix']) . 'log`';

    $update = $pdo->prepare("UPDATE $table SET `data` = :data WHERE `id` = :id");
    $changed = 0;
    // mode 3 = config changes; the log of the universe config has the same layout and no secret keys, it stays as it is
    foreach ($pdo->query("SELECT `id`, `data` FROM $table WHERE `mode` = 3 AND (" . implode(' OR ', array_map(static fn(string $key): string => '`data` LIKE ' . $pdo->quote('%' . $key . '%'), $secretKeys)) . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try {
            $entry = App\Game\Core\StoredData::decode($row['data']);
        } catch (Throwable) {
            continue; // unreadable row: leave it alone
        }
        // written as [old, new]
        $masked = array_map($mask, $entry);
        if ($masked !== $entry) {
            $update->execute([':data' => App\Game\Core\StoredData::encode($masked), ':id' => $row['id']]);
            $changed++;
        }
    }
    echo sprintf('%d admin log rows had secrets masked', $changed), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
