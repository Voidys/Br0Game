<?php

declare(strict_types=1);

namespace App\Game\Core;

/**
 * One-time conversion of the structured database columns from PHP serialize() to JSON (see StoredData).
 *
 * Used by install/migrations/migration_1794900200.php, which runs from the web upgrader (copied to the project root
 * and started as a CLI script) and from the command line. It works on a plain PDO connection, so it needs neither
 * the game bootstrap nor the container.
 *
 * Idempotent: only values that still look like a serialized array ("a:<n>:{") are touched, so a second run, or a run
 * on a database that was partly converted, converts the rest and leaves JSON rows alone. Every row is converted
 * in its own statement and verified (decoding the JSON gives exactly the structure the old value held) before it is
 * written; a row that cannot be converted safely (damaged, objects inside, too long for its column) is reported and
 * left as it is, which StoredData::decode() still reads.
 */
final class StoredDataMigrator
{
    /**
     * table (without prefix) => [primary key, [column => max JSON bytes, null for LONGTEXT]]. The limits are the
     * TEXT column size (65535); a longer value is left as legacy serialize() instead of being cut off.
     */
    public const COLUMNS = [
        'planets' => ['id', ['b_building_id' => 65535, 'b_hangar_id' => 65535]],
        'users' => ['id', ['b_tech_queue' => 65535, 'rights' => 65535]],
        'planet_wreckfield' => ['planetID', ['repair_order' => 65535]],
        'log' => ['id', ['data' => 65535]],
        'raports' => ['rid', ['raport' => null]],
    ];

    private const CHUNK = 200;

    /** @param string $prefix table prefix, e.g. "uni1_" */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $prefix,
    ) {}

    /**
     * Converts every affected column.
     *
     * @param callable(string): void|null $report receives one line per column and per failed row
     * @return array<string, array{converted: int, failed: int}> keyed "table.column"
     */
    public function run(?callable $report = null): array
    {
        $report ??= static function (string $line): void {};
        $result = [];

        foreach (self::COLUMNS as $table => [$primaryKey, $columns]) {
            foreach ($columns as $column => $maxBytes) {
                $result[$table . '.' . $column] = $this->convertColumn($table, $primaryKey, $column, $maxBytes, $report);
                $report(sprintf(
                    '%s.%s: %d converted, %d left as they were',
                    $table,
                    $column,
                    $result[$table . '.' . $column]['converted'],
                    $result[$table . '.' . $column]['failed'],
                ));
            }
        }

        return $result;
    }

    /**
     * Converts the legacy rows of one column in chunks of CHUNK rows (keyset pagination on the primary key).
     *
     * @param callable(string): void $report
     * @return array{converted: int, failed: int}
     */
    private function convertColumn(string $table, string $primaryKey, string $column, ?int $maxBytes, callable $report): array
    {
        $name = '`' . $this->prefix . $table . '`';
        $select = $this->pdo->prepare(
            "SELECT `$primaryKey` AS pk, `$column` AS value FROM $name WHERE `$column` LIKE 'a:%' AND `$primaryKey` > :last ORDER BY `$primaryKey` LIMIT " . self::CHUNK,
        );
        // the old value is part of the condition: a row changed in the meantime is not overwritten
        $update = $this->pdo->prepare("UPDATE $name SET `$column` = :new WHERE `$primaryKey` = :pk AND `$column` = :old");

        $converted = 0;
        $failed = 0;
        $last = is_numeric($this->firstKeyBound($table, $primaryKey)) ? '0' : '';

        do {
            $select->execute([':last' => $last]);
            $rows = $select->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $last = (string) $row['pk'];
                $json = $this->convertValue((string) $row['value'], $maxBytes);
                if ($json === null) {
                    ++$failed;
                    $report(sprintf('%s.%s %s: not converted (damaged, contains objects or too long)', $table, $column, $row['pk']));

                    continue;
                }
                $update->execute([':new' => $json, ':pk' => $row['pk'], ':old' => $row['value']]);
                ++$converted;
            }
        } while (count($rows) === self::CHUNK);

        return ['converted' => $converted, 'failed' => $failed];
    }

    /**
     * Converts one value and proves the result: the JSON must decode to exactly the structure the legacy value held.
     *
     * @return string|null JSON, or null when the value must be left alone
     */
    public function convertValue(string $legacy, ?int $maxBytes = null): ?string
    {
        try {
            $data = StoredData::decode($legacy);
            if (self::containsObject($data)) {
                return null;
            }
            $json = StoredData::encode($data);
        } catch (\UnexpectedValueException|\JsonException) {
            return null;
        }

        if (($maxBytes !== null && strlen($json) > $maxBytes) || StoredData::decode($json) !== $data) {
            return null;
        }

        return $json;
    }

    /** @param array<mixed> $data */
    private static function containsObject(array $data): bool
    {
        foreach ($data as $value) {
            if (is_object($value) || (is_array($value) && self::containsObject($value))) {
                return true;
            }
        }

        return false;
    }

    /** a sample key of the table, to tell numeric from string keys (rid of the reports is a string) */
    private function firstKeyBound(string $table, string $primaryKey): mixed
    {
        $value = $this->pdo->query('SELECT `' . $primaryKey . '` FROM `' . $this->prefix . $table . '` LIMIT 1')->fetchColumn();

        return $value === false ? 0 : $value;
    }
}
