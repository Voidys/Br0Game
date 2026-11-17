<?php

declare(strict_types=1);

namespace App\Game\Core;

/**
 * Reads and writes structured data that is stored in database columns (build/tech/hangar queues, repair
 * orders, admin rights, admin log entries, battle reports).
 *
 * The storage format is JSON. Writers call encode(), readers decode() or tryDecode().
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
     * Decodes a column value written by encode().
     *
     * @param string|null $stored column value; null and '' mean "no data" and give []
     * @return array<mixed> always an array (assoc, keys as written)
     * @throws \UnexpectedValueException when the value is not JSON
     */
    public static function decode(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }

        try {
            $data = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException('Stored data is not valid JSON.', 0, $e);
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
}
