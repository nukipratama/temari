<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * Numbers as Indonesian copy renders them: comma decimal, plain thousands.
 *
 * The convention `docs/voice-and-tone.md` fixes for every number a human reads.
 * Payload values, prompt arguments and cache keys keep the dot and must not
 * pass through here.
 */
final class DecimalFormatter
{
    public static function decimal(float $value, int $precision = 1): string
    {
        return number_format($value, $precision, ',', '');
    }

    /** Same, minus a trailing ",0" — "35 menit", never "35,0 menit". */
    public static function trimmed(float $value, int $precision = 1): string
    {
        $formatted = self::decimal($value, $precision);

        return str_contains($formatted, ',')
            ? rtrim(rtrim($formatted, '0'), ',')
            : $formatted;
    }
}
