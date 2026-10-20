<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * Metres to kilometres at one of two blessed precisions.
 *
 * {@see self::COPY} is the one-decimal ceiling `docs/voice-and-tone.md` puts on user-facing numbers;
 * {@see self::EXACT} is for "longest run" record readings and Inertia props the client re-formats.
 * {@see self::kmString()} is copy and formats through {@see DecimalFormatter}; payloads and prompt
 * arguments want the float from {@see self::km()}.
 */
final class DistanceFormatter
{
    public const int COPY = 1;

    public const int EXACT = 2;

    public static function km(float $meters, int $precision = self::COPY): float
    {
        return round($meters / 1000, $precision);
    }

    public static function kmString(?float $meters, int $precision = self::COPY): ?string
    {
        return $meters === null ? null : DecimalFormatter::decimal(self::km($meters, $precision), $precision);
    }
}
