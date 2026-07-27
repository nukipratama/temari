<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * Karvonen %HRR zone derivation and the bounds a max-HR value must satisfy.
 *
 * Lives in the domain layer because both the settings screen and the ingest
 * pipeline derive zones: the editor when the athlete types a new max, ingest
 * when a run's own peak beats the stored one. Keeping the maths here means the
 * background job never has to reach into an HTTP request to reuse it.
 */
final class HeartRateZones
{
    /**
     * Fraction of heart-rate reserve at which each zone begins. These reproduce
     * the {@see config('runner.hr_zones')} defaults at max 180 / rest 55
     * (Z1 lo 116, Z2 138, Z3 154, Z4 168, Z5 176).
     *
     * @var array<int, float>
     */
    private const array BREAKPOINTS = [0.488, 0.664, 0.792, 0.904, 0.968];

    /**
     * High sentinel for Z5's open-ended upper bound, matching the Z5 `hi` in
     * {@see config('runner.hr_zones')}.
     */
    private const int Z5_SENTINEL_HI = 999;

    /** @var array<int, string> */
    public const array KEYS = ['Z1', 'Z2', 'Z3', 'Z4', 'Z5'];

    /**
     * Range a max HR must fall in to be believable. Anything above the ceiling
     * is interference rather than physiology, and one such reading would
     * otherwise reshape every zone. Shared so the manual editor and the
     * ingest-time auto-raise cannot drift apart on what counts as plausible.
     */
    public const int MIN_MAX_HR = 120;

    public const int MAX_MAX_HR = 220;

    /**
     * Derive Z1-Z5 bands from max/resting HR. Each zone's `lo` is
     * `round(resting + pct * (max - resting))`; its `hi` is the next zone's
     * `lo`, with Z5's `hi` fixed at the open-ended sentinel.
     *
     * @return array<string, array{lo:int, hi:int}>
     */
    public static function derive(int $maxHr, int $restingHr): array
    {
        $reserve = $maxHr - $restingHr;

        $los = array_map(
            static fn (float $pct): int => (int) round($restingHr + $pct * $reserve),
            self::BREAKPOINTS,
        );

        $zones = [];
        foreach (self::KEYS as $index => $key) {
            $isLast = $index === count(self::KEYS) - 1;
            $zones[$key] = [
                'lo' => $los[$index],
                'hi' => $isLast ? self::Z5_SENTINEL_HI : $los[$index + 1],
            ];
        }

        return $zones;
    }

    public static function isPlausibleMax(int $maxHr): bool
    {
        return $maxHr >= self::MIN_MAX_HR && $maxHr <= self::MAX_MAX_HR;
    }
}
