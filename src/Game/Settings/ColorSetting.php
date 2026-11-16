<?php

declare(strict_types=1);

namespace App\Game\Settings;

/**
 * Colour settings of a player (`color*` columns) are written into inline CSS, so only hex colours (#rgb, #rrggbb,
 * #rrggbbaa) are accepted; anything else falls back to the default.
 */
final class ColorSetting
{
    private const PATTERN = '/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3}(?:[0-9a-fA-F]{2})?)?$/D';

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /** Returns the colour when valid, else the default. */
    public static function sanitize(mixed $value, string $default): string
    {
        return self::isValid($value) ? (string) $value : $default;
    }
}
