<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * Metres to kilometres at one of two blessed precisions.
 *
 * {@see self::COPY} is the one-decimal ceiling `docs/voice-and-tone.md` puts on
 * user-facing numbers; {@see self::EXACT} is the two-decimal form the record and
 * per-run surfaces render.
 */
final class DistanceFormatter
{
    public const int COPY = 1;

    public const int EXACT = 2;

    public static function km(float $meters, int $precision = self::COPY): float
    {
        return round($meters / 1000, $precision);
    }

    public static function kmOrNull(?float $meters, int $precision = self::COPY): ?float
    {
        return $meters === null ? null : self::km($meters, $precision);
    }

    public static function kmString(?float $meters, int $precision = self::COPY): ?string
    {
        return $meters === null ? null : number_format(self::km($meters, $precision), $precision);
    }
}
