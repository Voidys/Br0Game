<?php

declare(strict_types=1);

namespace App\Game\Core;

/**
 * Reads and writes structured data that is stored in database columns (build/tech/hangar queues, repair
 * orders, admin rights, admin log entries, battle reports).
 *
 * The storage format is JSON. Rows written before the switch hold PHP serialize() strings; decode() still
 * reads those, but only plain arrays and scalars (allowed_classes is false, so a manipulated row can never
 * instantiate an object) and nothing else. Writers always call encode(). The migration
 * (install/migrations/migration_1794900200.php, see StoredDataMigrator) converts all legacy rows once, so the
 * legacy branch is only a safety net for rows written by a not yet migrated code path.
 *
 * This class is the only place in src/ that calls unserialize().
 */
final class StoredData
{
    /**
     * Encodes data for a database column.
     *
     * @param array<mixed> $data
     * @throws \JsonException when the data cannot be represented (INF/NAN); invalid UTF-8 is substituted
     */
    public static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Decodes a column value written by encode() or (legacy) by serialize().
     *
     * @param string|null $stored column value; null and '' mean "no data" and give []
     * @return array<mixed> always an array (assoc, keys as written)
     * @throws \UnexpectedValueException when the value is neither JSON nor a legacy serialized array
     */
    public static function decode(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }

        if (self::isLegacySerialized($stored)) {
            // a damaged value makes unserialize() raise a notice before it returns false: swallow that, the false is handled below
            set_error_handler(static fn(): bool => true);
            try {
                $data = unserialize($stored, ['allowed_classes' => false]);
            } finally {
                restore_error_handler();
            }
            if (!is_array($data)) {
                throw new \UnexpectedValueException('Stored data is not a valid serialized array.');
            }

            return $data;
        }

        try {
            $data = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException('Stored data is neither JSON nor a legacy serialized array.', 0, $e);
        }

        if (!is_array($data)) {
            throw new \UnexpectedValueException('Stored data is not an array.');
        }

        return $data;
    }

    /**
     * Like decode(), but gives $default instead of throwing for an unreadable value (damaged rows must not
     * take a page down: admin log view, rights).
     *
     * @param array<mixed> $default
     * @return array<mixed>
     */
    public static function tryDecode(?string $stored, array $default = []): array
    {
        try {
            return self::decode($stored);
        } catch (\UnexpectedValueException) {
            return $default;
        }
    }

    /** true when the value is a PHP-serialized array ("a:<n>:{...}"), the pre-JSON storage format */
    public static function isLegacySerialized(string $stored): bool
    {
        return preg_match('/^a:\d+:\{/', $stored) === 1;
    }

    /**
     * Decodes the game session object of a Symfony session written before sessions were stored as arrays,
     * so players stay logged in across the deploy. Only the Session class itself may be instantiated.
     * Remove together with the 'obj' fallback in SessionManager once old sessions have expired.
     *
     * @return Session|null null when the value is not a serialized Session
     */
    public static function decodeLegacySession(mixed $stored): ?Session
    {
        if (!is_string($stored) || !str_starts_with($stored, 'O:')) {
            return null;
        }

        $session = unserialize($stored, ['allowed_classes' => [Session::class]]);

        return $session instanceof Session ? $session : null;
    }
}
