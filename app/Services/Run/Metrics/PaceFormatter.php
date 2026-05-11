<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * Single source of truth for pace serialization. Pace is always
 * seconds-per-kilometre internally and `M:SS` for display.
 */
final class PaceFormatter
{
    public static function format(float $secPerKm): string
    {
        $rounded = (int) round($secPerKm);

        return sprintf('%d:%02d', intdiv($rounded, 60), $rounded % 60);
    }

    public static function parse(string $label): ?float
    {
        if (! preg_match('/^(\d+):(\d{2})$/', $label, $m)) {
            return null;
        }

        return (float) $m[1] * 60 + (float) $m[2];
    }
}
