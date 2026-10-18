<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Support\Numeric;

/**
 * Where a run's prescribed work blocks or reps actually happened: read off the
 * laps when they line up with the prescription, else found in the raw stream.
 * See `docs/decisions/a-quality-day-is-judged-on-every-block.md`.
 */
final class WorkBlocks
{
    /** The share of a prescribed block's minutes or km a lap group must cover to stand for it. */
    public const float MIN_COVERAGE = 0.9;

    /**
     * Consecutive work laps merged into blocks, so an autosplit inside a block
     * never cuts it in two. A lap is work when it ran quicker than halfway
     * between the slowest block pace and easy pace. Null when the laps are just
     * the kilometre grid, or the merged blocks don't match the prescription
     * one for one.
     *
     * @param  array<int, array<string, mixed>>  $laps
     * @param  non-empty-list<array{seconds: float, km: float}>  $prescribed
     * @return list<array{seconds: float, km: float}>|null
     */
    public static function fromLaps(array $laps, array $prescribed, int $workPaceSec, int $easyPaceSec): ?array
    {
        $laps = array_values($laps);
        $paces = IntervalDetector::lapPaces($laps);
        if (count($laps) < 2 || $paces === []) {
            return null;
        }

        $threshold = ($workPaceSec + $easyPaceSec) / 2;
        $blocks = [];
        $current = null;
        foreach ($laps as $position => $lap) {
            $pace = $paces[$position] ?? null;
            if ($pace === null || $pace > $threshold) {
                if ($current !== null) {
                    $blocks[] = $current;
                    $current = null;
                }

                continue;
            }
            $current = [
                'seconds' => ($current['seconds'] ?? 0.0) + Numeric::float($lap['elapsed_sec']),
                'km' => ($current['km'] ?? 0.0) + Numeric::float($lap['distance_m']) / 1000,
            ];
        }
        if ($current !== null) {
            $blocks[] = $current;
        }

        if (count($blocks) !== count($prescribed)) {
            return null;
        }
        foreach ($blocks as $index => $block) {
            if ($block['seconds'] < $prescribed[$index]['seconds'] * self::MIN_COVERAGE && $block['km'] < $prescribed[$index]['km'] * self::MIN_COVERAGE) {
                return null;
            }
        }

        return $blocks;
    }

    /**
     * The quickest stretches of the given lengths that don't overlap, longest
     * length first, returned in the order they were run. Null when the
     * recording can't hold them all.
     *
     * @param  array<string, mixed>  $stream  raw Strava streams dict
     * @param  non-empty-list<int>  $lengths  seconds
     * @return list<array{seconds: float, km: float}>|null
     */
    public static function fromStream(array $stream, array $lengths): ?array
    {
        $time = array_map(Numeric::float(...), self::series($stream, 'time'));
        $velocity = array_map(Numeric::float(...), self::series($stream, 'velocity_smooth'));
        $count = min(count($time), count($velocity));
        if ($count < 2) {
            return null;
        }

        $cumulative = [0.0];
        for ($i = 0; $i < $count - 1; $i++) {
            $cumulative[] = $cumulative[$i] + $velocity[$i] * max(0.0, $time[$i + 1] - $time[$i]);
        }

        rsort($lengths);
        $taken = [];
        foreach ($lengths as $length) {
            $stretch = self::quickestFree($time, $velocity, $cumulative, $count, $length, $taken);
            if ($stretch === null) {
                return null;
            }
            $taken[] = $stretch;
        }

        usort($taken, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return array_map(static fn (array $stretch): array => ['seconds' => (float) $stretch['length'], 'km' => $stretch['metres'] / 1000], $taken);
    }

    /**
     * @param  list<float>  $time
     * @param  list<float>  $velocity
     * @param  list<float>  $cumulative  metres covered by each sample
     * @param  list<array{start: float, length: int, metres: float}>  $taken
     * @return array{start: float, length: int, metres: float}|null
     */
    private static function quickestFree(array $time, array $velocity, array $cumulative, int $count, int $length, array $taken): ?array
    {
        $candidates = [];
        $end = 0;
        for ($start = 0; $start < $count - 1; $start++) {
            while ($end < $count - 1 && $time[$end] - $time[$start] < $length) {
                $end++;
            }
            $span = $time[$end] - $time[$start];
            if ($span < $length) {
                break;
            }
            $overshoot = $span - $length;
            $metres = $cumulative[$end] - $cumulative[$start] - $overshoot * $velocity[$end - 1];
            $candidates[] = ['start' => $time[$start], 'length' => $length, 'metres' => $metres];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['metres'] <=> $a['metres']);
        foreach ($candidates as $candidate) {
            if (array_all($taken, static fn (array $other): bool => $candidate['start'] >= $other['start'] + $other['length'] || $other['start'] >= $candidate['start'] + $length)) {
                return $candidate['metres'] > 0.0 ? $candidate : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $stream
     * @return list<mixed>
     */
    private static function series(array $stream, string $key): array
    {
        $payload = $stream[$key] ?? null;
        $data = is_array($payload) ? ($payload['data'] ?? $payload) : [];

        return is_array($data) ? array_values($data) : [];
    }
}
