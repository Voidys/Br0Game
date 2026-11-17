<?php

declare(strict_types=1);

namespace App\Game\Model;

/**
 * Keeps the numeric type of a game-data value: an int stays int, a float stays float, a numeric string becomes the
 * number PHP arithmetic would make of it (`"304000"` → int, `"1.5"` → float), anything else (NULL, empty) is 0.
 * The catalog must hand out exactly what the former arrays held, because results (battle reports, sums compared
 * with `===`, JSON) depend on int vs float.
 */
final class Numeric
{
    /** Returns the value as int or float, keeping its numeric type; non-numeric values give 0. */
    public static function of(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return $value + 0;
        }
        if (is_bool($value)) {
            return (int) $value;
        }

        return 0;
    }
}
