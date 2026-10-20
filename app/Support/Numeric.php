<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Casts a value the caller already knows is numeric or null (stream samples,
 * stored split JSON), asserting that contract where assertions run.
 */
final class Numeric
{
    public static function float(mixed $value): float
    {
        assert($value === null || is_numeric($value));

        return (float) $value;
    }

    public static function int(mixed $value): int
    {
        assert($value === null || is_numeric($value));

        return (int) $value;
    }
}
