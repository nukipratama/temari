<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * Metres to kilometres at one of two blessed precisions.
 *
 * {@see self::COPY} is the one-decimal ceiling `docs/voice-and-tone.md` puts on
 * user-facing numbers, and every copy and prompt site takes it. {@see self::EXACT}
 * is left to the "longest run" record readings and to Inertia props the client
 * re-formats itself.
 *
 * {@see self::kmString()} is copy — it formats through {@see DecimalFormatter}.
 * Callers building a payload or a prompt argument want the float from
 * {@see self::km()} instead.
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
        return $meters === null ? null : DecimalFormatter::decimal(self::km($meters, $precision), $precision);
    }
}
