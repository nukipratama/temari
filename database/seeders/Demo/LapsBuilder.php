<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use function count;

class LapsBuilder
{
    /**
     * Cuts the synthesized streams into Strava-shaped `laps[]` rows. Lap lengths
     * are data: pass null for the watch's plain 1 km auto-split, or an explicit
     * list for a manually-lapped session. The last lap absorbs whatever distance
     * is left, the way a lap does when the run is stopped mid-lap.
     *
     * @param  array<string, array{data: list<int|float|array{float, float}>}>  $streams
     * @param  list<int>|null  $lapDistancesM
     * @return list<array<string, int|float|string>>
     */
    public function build(array $streams, ?array $lapDistancesM = null): array
    {
        $time = $streams['time']['data'] ?? [];
        $distance = $streams['distance']['data'] ?? [];
        $heartrate = $streams['heartrate']['data'] ?? [];

        $n = count($time);
        if ($n < 2 || count($distance) !== $n) {
            return [];
        }

        $laps = [];
        $startIdx = 0;
        $lapStart = 0.0;
        $remaining = $lapDistancesM;
        $target = $this->nextTarget($remaining, 0.0);

        for ($i = 1; $i < $n && $target !== null; $i++) {
            if ((float) $distance[$i] < $target) {
                continue;
            }

            // The lap is the length the watch was asked for, not the distance
            // between the two stream samples it happened to land between — except
            // for a lap that ends with the run, which keeps whatever it covered.
            $lapEnd = $i === $n - 1 ? (float) end($distance) : $target;
            $laps[] = $this->lapRow(count($laps) + 1, $startIdx, $i, $lapEnd - $lapStart, $time, $heartrate);
            $startIdx = $i;
            $lapStart = $lapEnd;
            $target = $this->nextTarget($remaining, $target);
        }

        if ($startIdx < $n - 1) {
            $laps[] = $this->lapRow(count($laps) + 1, $startIdx, $n - 1, (float) end($distance) - $lapStart, $time, $heartrate);
        }

        return $laps;
    }

    /**
     * Cumulative distance at which the next lap ends. Null once an explicit lap
     * list runs out; the 1 km grid never does.
     *
     * @param  list<int>|null  $remaining
     */
    private function nextTarget(?array &$remaining, float $from): ?float
    {
        if ($remaining === null) {
            return $from + 1000.0;
        }

        $next = array_shift($remaining);

        return $next === null ? null : $from + $next;
    }

    /**
     * @param  list<int|float|array{float, float}>  $streamTime
     * @param  list<int|float|array{float, float}>  $streamHeartrate
     * @return array<string, int|float|string>
     */
    private function lapRow(
        int $index,
        int $startIdx,
        int $endIdx,
        float $distance,
        array $streamTime,
        array $streamHeartrate,
    ): array {
        $elapsed = (float) $streamTime[$endIdx] - (float) $streamTime[$startIdx];
        $avgSpeed = $elapsed > 0 ? $distance / $elapsed : 0.0;

        $row = [
            'lap_index' => $index,
            'split' => $index,
            'name' => "Lap {$index}",
            'distance' => round($distance, 1),
            // Auto-pause is off on the watch, so elapsed and moving agree on every lap.
            'elapsed_time' => (int) round($elapsed),
            'moving_time' => (int) round($elapsed),
            'average_speed' => round($avgSpeed, 3),
            'start_index' => $startIdx,
            'end_index' => $endIdx,
        ];

        if ($streamHeartrate !== []) {
            $row['average_heartrate'] = round(StreamStats::sliceMean($streamHeartrate, $startIdx, $endIdx), 1);
        }

        return $row;
    }
}
