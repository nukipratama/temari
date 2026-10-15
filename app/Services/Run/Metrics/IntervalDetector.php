<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Services\Run\Ingest\KmSplitBuilder;

/**
 * Pure interval-detection heuristic, extracted from
 * {@see \App\Services\AI\Agent\Tools\LapsTool::reps()} so the periodizer and
 * other session-structure code can reuse it without going through the LLM
 * tool layer. No behavior change from the original inline logic.
 */
final class IntervalDetector
{
    /**
     * How far apart (sec/km) the quickest and slowest lap must sit before the
     * fast/slow alternation is read as deliberate. A rep sits a minute or more
     * off its recovery; anything tighter is ordinary drift over manual laps.
     */
    public const float REP_PACE_GAP_SEC = 45.0;

    /**
     * Positions of the work laps, when the laps repeat a fast/slow structure at
     * all: at least two quick laps, none of them back to back, and a spread wide
     * enough that the split into quick and easy means something. An even set of
     * manual laps comes back empty, which is the reading "no structure here".
     *
     * @param  array<int, float>  $paces  pace in sec/km, keyed by lap position
     * @return list<int>
     */
    public static function detect(array $paces): array
    {
        if (count($paces) < 3 || max($paces) - min($paces) < self::REP_PACE_GAP_SEC) {
            return [];
        }

        $threshold = (min($paces) + max($paces)) / 2;
        $work = array_keys(array_filter($paces, fn (float $pace): bool => $pace <= $threshold));
        if (count($work) < 2) {
            return [];
        }

        foreach ($work as $position) {
            if (in_array($position + 1, $work, true)) {
                return [];
            }
        }

        return $work;
    }

    /**
     * Each lap's pace, keyed by position; empty when the laps are just the
     * device's automatic kilometre grid.
     *
     * @param  array<int, array<string, mixed>>  $laps
     * @return array<int, float>
     */
    public static function lapPaces(array $laps): array
    {
        $distances = array_map(static fn (array $lap): float => is_numeric($lap['distance_m'] ?? null) ? (float) $lap['distance_m'] : 0.0, $laps);
        if (KmSplitBuilder::isPlainKmGrid(array_values($distances))) {
            return [];
        }

        $paces = [];
        foreach ($laps as $position => $lap) {
            $pace = PaceCalculator::secPerKm($distances[$position], is_numeric($lap['elapsed_sec'] ?? null) ? (float) $lap['elapsed_sec'] : null);
            if ($pace !== null) {
                $paces[$position] = $pace;
            }
        }

        return $paces;
    }
}
