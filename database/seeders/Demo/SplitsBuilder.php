<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use function count;

/**
 * Collapses synthesized 1 Hz streams into `splits_metric` — the per-km
 * shape PersonalRecords::detectAndStore() and the technical fold on
 * /runs/{id} both read. One row per completed kilometre.
 *
 * Mirrors Strava's `splits_metric` payload:
 *   ['split', 'distance', 'elapsed_time', 'moving_time',
 *    'average_speed', 'average_heartrate', 'pace_zone']
 */
class SplitsBuilder
{
    /**
     * @param  array<string, array{data: list<int|float|array{float, float}>}>  $streams
     * @return list<array<string, int|float>>
     */
    public function build(array $streams): array
    {
        $time = $streams['time']['data'] ?? [];
        $distance = $streams['distance']['data'] ?? [];
        $velocity = $streams['velocity_smooth']['data'] ?? [];
        $heartrate = $streams['heartrate']['data'] ?? [];

        $n = count($time);
        if ($n < 2 || count($distance) !== $n) {
            return [];
        }

        $splits = [];
        $splitIndex = 1;
        $kmTarget = 1000.0;
        $startIdx = 0;

        for ($i = 0; $i < $n; $i++) {
            if ((float) $distance[$i] < $kmTarget) {
                continue;
            }
            $splits[] = $this->splitRow(
                index: $splitIndex,
                startIdx: $startIdx,
                endIdx: $i,
                streamTime: $time,
                streamDistance: $distance,
                streamVelocity: $velocity,
                streamHeartrate: $heartrate,
            );
            $splitIndex++;
            $startIdx = $i;
            $kmTarget += 1000.0;
        }

        // Trailing partial km (e.g. last 300m of a 5.3km run).
        if ($startIdx < $n - 1 && (float) $distance[$n - 1] - (float) $distance[$startIdx] >= 100) {
            $splits[] = $this->splitRow(
                index: $splitIndex,
                startIdx: $startIdx,
                endIdx: $n - 1,
                streamTime: $time,
                streamDistance: $distance,
                streamVelocity: $velocity,
                streamHeartrate: $heartrate,
            );
        }

        return $splits;
    }

    /**
     * @param  list<int|float|array{float, float}>  $streamTime
     * @param  list<int|float|array{float, float}>  $streamDistance
     * @param  list<int|float|array{float, float}>  $streamVelocity
     * @param  list<int|float|array{float, float}>  $streamHeartrate
     * @return array<string, int|float>
     */
    private function splitRow(
        int $index,
        int $startIdx,
        int $endIdx,
        array $streamTime,
        array $streamDistance,
        array $streamVelocity,
        array $streamHeartrate,
    ): array {
        $elapsed = (float) $streamTime[$endIdx] - (float) $streamTime[$startIdx];
        $distance = (float) $streamDistance[$endIdx] - (float) $streamDistance[$startIdx];
        $avgSpeed = $elapsed > 0 ? $distance / $elapsed : 0.0;
        $paceSecPerKm = $avgSpeed > 0 ? 1000.0 / $avgSpeed : 0;

        $row = [
            'split' => $index,
            'distance' => round($distance, 1),
            'elapsed_time' => (int) round($elapsed),
            'moving_time' => (int) round($elapsed),
            'average_speed' => round($avgSpeed, 3),
            'pace_zone' => $this->paceZoneFor($paceSecPerKm),
        ];

        // Strava omits average_heartrate from splits when no HR sensor is
        // paired; mirror that here so PersonalRecords + the per-km table
        // don't render a misleading 0.0.
        if ($streamHeartrate !== []) {
            $row['average_heartrate'] = round($this->meanScalar($streamHeartrate, $startIdx, $endIdx), 1);
        }

        return $row;
    }

    /**
     * @param  list<int|float|array{float, float}>  $stream
     */
    private function meanScalar(array $stream, int $startIdx, int $endIdx): float
    {
        $sum = 0.0;
        $count = 0;
        for ($i = $startIdx; $i <= $endIdx; $i++) {
            $value = $stream[$i] ?? null;
            if (! is_int($value) && ! is_float($value)) {
                continue;
            }
            $sum += (float) $value;
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    /** Crude pace-band stamp Strava emits; 1 (slow) … 5 (fast). */
    private function paceZoneFor(float $secPerKm): int
    {
        return match (true) {
            $secPerKm <= 0 => 0,
            $secPerKm > 480 => 1,
            $secPerKm > 390 => 2,
            $secPerKm > 330 => 3,
            $secPerKm > 270 => 4,
            default => 5,
        };
    }
}
