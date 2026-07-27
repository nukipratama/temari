<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * Turns `pace_variability_sec` (the spread of per-km split paces) into the band
 * a narrator can talk about.
 *
 * The raw figure is never handed to the LLM: "pace variability 12 detik" reads
 * as a measurement a runner is expected to already understand, and the model
 * quotes it verbatim. The band carries the same information in words.
 */
final class PaceConsistency
{
    /** Splits this tight read as deliberate, machine-even pacing. */
    private const int VERY_EVEN_SEC = 8;

    /** Normal variation for a controlled run. */
    private const int EVEN_SEC = 15;

    /** Noticeable swing, still within a single intended effort. */
    private const int UNEVEN_SEC = 20;

    public static function label(float|int|null $variabilitySec): ?string
    {
        if ($variabilitySec === null) {
            return null;
        }

        return match (true) {
            $variabilitySec <= self::VERY_EVEN_SEC => 'sangat rata',
            $variabilitySec <= self::EVEN_SEC => 'cukup rata',
            $variabilitySec <= self::UNEVEN_SEC => 'agak naik-turun',
            default => 'naik-turun',
        };
    }

    /** Whether the pacing is even enough to be worth complimenting. */
    public static function isPraiseworthy(float|int|null $variabilitySec): bool
    {
        return $variabilitySec !== null && $variabilitySec <= self::EVEN_SEC;
    }

    /** Whether the splits are tight enough to call out as notably even. */
    public static function isVeryEven(float|int|null $variabilitySec): bool
    {
        return $variabilitySec !== null && $variabilitySec <= self::VERY_EVEN_SEC;
    }

    /** Whether the swing is wide enough to be worth flagging. */
    public static function isNotablyUneven(float|int|null $variabilitySec): bool
    {
        return $variabilitySec !== null && $variabilitySec > self::UNEVEN_SEC;
    }
}
