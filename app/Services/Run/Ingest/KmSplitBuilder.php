<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Services\Run\Metrics\PaceCalculator;
use App\Services\Run\Metrics\PaceFormatter;

/**
 * Canonical per-km derivation, on the watch's own time basis.
 *
 * Strava's `splits_metric.moving_time` applies Strava's auto-pause heuristic and
 * its `distance` stream is a smoothed curve that drifts off raw GPS mid-run, so
 * splits derived from that pair disagree with the wrist by up to 86 s/km. Three
 * sources, first that yields rows:
 *
 *  1. Laps that already form a ~1 km grid — the watch's own `elapsed_time`,
 *     which matches the wrist exactly.
 *  2. Cumulative haversine over `latlng`, scaled to the device's own total
 *     distance and interpolated at each km boundary. Within ~2 s of the wrist,
 *     and the fallback whenever manual laps break the grid.
 *  3. `splits_metric` read on `elapsed_time` — last resort for a run with no
 *     GPS trace at all, e.g. a treadmill.
 */
final class KmSplitBuilder
{
    /** Distance (m) at or above which a segment counts as a full kilometre. */
    private const float FULL_KM_MIN_DISTANCE_M = 950;

    /** How far a lap may sit off 1000 m and still count as part of a km grid. */
    private const float KM_GRID_TOLERANCE_M = 50;

    private const float METRES_PER_KM = 1000;

    /** IUGG mean Earth radius (m). */
    private const float EARTH_RADIUS_M = 6371008.8;

    /**
     * @param  array<int, array<string, mixed>>|null  $laps  Strava `laps[]` as ingested
     * @param  list<mixed>  $latlng  parsed `latlng` stream
     * @param  list<mixed>  $time  parsed `time` stream
     * @param  list<mixed>  $heartrate  parsed `heartrate` stream
     * @param  array<int, array<string, mixed>>|null  $splitsMetric
     * @param  float|null  $deviceDistanceM  the activity's own total distance
     * @return list<array<string, int|string>>
     */
    public function perKm(?array $laps, array $latlng, array $time, array $heartrate, ?array $splitsMetric, ?float $deviceDistanceM): array
    {
        $rows = $this->fromKmGridLaps($this->usableLaps($laps));
        if ($rows !== []) {
            return $rows;
        }

        $rows = $this->fromGeoStreams($latlng, $time, $heartrate, $deviceDistanceM);
        if ($rows !== []) {
            return $rows;
        }

        return $this->fromSplitsMetric($splitsMetric);
    }

    /**
     * The lap list as its own rows — never bucketed into kilometres, since a lap
     * is whatever length the watch was asked for.
     *
     * @param  array<int, array<string, mixed>>|null  $laps
     * @return list<array<string, int|string>>
     */
    public function laps(?array $laps): array
    {
        $rows = [];
        foreach ($this->usableLaps($laps) as $i => $lap) {
            $distance = (float) $lap['distance'];
            $elapsed = (float) $lap['elapsed_time'];
            $rows[] = [
                'lap' => $i + 1,
                'distance_m' => (int) round($distance),
                'elapsed_sec' => (int) round($elapsed),
                'pace' => PaceFormatter::format(PaceCalculator::secPerKm($distance, $elapsed) ?? 0.0),
            ] + $this->averages($lap);
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $laps
     * @return list<array<string, mixed>>
     */
    private function usableLaps(?array $laps): array
    {
        if ($laps === null) {
            return [];
        }

        return array_values(array_filter(
            $laps,
            fn (array $lap): bool => (float) ($lap['distance'] ?? 0) > 0
                && (float) ($lap['elapsed_time'] ?? 0) > 0,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $laps
     * @return list<array<string, int|string>>
     */
    private function fromKmGridLaps(array $laps): array
    {
        if (! $this->isKmGrid($laps)) {
            return [];
        }

        $rows = [];
        foreach ($laps as $i => $lap) {
            $distance = (float) $lap['distance'];
            if ($distance < self::FULL_KM_MIN_DISTANCE_M) {
                continue;
            }
            $rows[] = $this->row($i + 1, (float) $lap['elapsed_time'], $distance) + $this->averages($lap);
        }

        return $rows;
    }

    /**
     * Whether the laps are the watch's plain 1 km auto-split: every lap sits on
     * the grid, and only the final one may fall short because the run ended
     * mid-lap. One manual lap press anywhere breaks it.
     *
     * @param  list<array<string, mixed>>  $laps
     */
    private function isKmGrid(array $laps): bool
    {
        $last = count($laps) - 1;
        if ($last < 0) {
            return false;
        }

        foreach ($laps as $i => $lap) {
            $distance = (float) $lap['distance'];
            $long = $distance > self::METRES_PER_KM + self::KM_GRID_TOLERANCE_M;
            $short = $distance < self::METRES_PER_KM - self::KM_GRID_TOLERANCE_M;
            if ($long || ($short && $i !== $last)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Per-km rows from the GPS trace: cumulative haversine over `latlng`, scaled
     * so the trace's total equals the device's own distance, then read off at
     * each km boundary by interpolating the `time` stream.
     *
     * @param  list<mixed>  $latlng
     * @param  list<mixed>  $time
     * @param  list<mixed>  $heartrate
     * @return list<array<string, int|string>>
     */
    private function fromGeoStreams(array $latlng, array $time, array $heartrate, ?float $deviceDistanceM): array
    {
        $n = min(count($latlng), count($time));
        if ($n < 2) {
            return [];
        }

        $cumulative = $this->cumulativeDistance($latlng, $n);
        $traced = $cumulative[$n - 1];
        if ($traced <= 0) {
            return [];
        }

        // A constant scale error in the haversine radius cancels here, so the km
        // boundaries stay exact even though the trace itself never is.
        $scale = $deviceDistanceM !== null && $deviceDistanceM > 0 ? $deviceDistanceM / $traced : 1.0;
        $kmCount = (int) floor($traced * $scale / self::METRES_PER_KM);

        $rows = [];
        $index = 1;
        $startIndex = 0;
        $startTime = (float) $time[0];
        for ($km = 1; $km <= $kmCount; $km++) {
            $target = $km * self::METRES_PER_KM / $scale;
            while ($index < $n - 1 && $cumulative[$index] < $target) {
                $index++;
            }
            $boundaryTime = $this->interpolate($cumulative, $time, $index, $target);
            $rows[] = $this->row($km, $boundaryTime - $startTime, self::METRES_PER_KM)
                + $this->meanHeartRate($heartrate, $startIndex, $index);
            $startIndex = $index;
            $startTime = $boundaryTime;
        }

        return $rows;
    }

    /**
     * @param  list<mixed>  $latlng  per-sample [lat, lng] pairs
     * @return list<float>
     */
    private function cumulativeDistance(array $latlng, int $n): array
    {
        $cumulative = [0.0];
        for ($i = 1; $i < $n; $i++) {
            $cumulative[] = $cumulative[$i - 1] + $this->haversine($latlng[$i - 1], $latlng[$i]);
        }

        return $cumulative;
    }

    private function haversine(mixed $from, mixed $to): float
    {
        if (! is_array($from) || ! is_array($to) || count($from) < 2 || count($to) < 2) {
            return 0.0;
        }

        $lat1 = deg2rad((float) $from[0]);
        $lat2 = deg2rad((float) $to[0]);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad((float) $to[1] - (float) $from[1]);
        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($h)));
    }

    /**
     * Time at which the trace crosses $target metres, linearly between the
     * bracketing samples.
     *
     * @param  list<float>  $cumulative
     * @param  list<mixed>  $time
     */
    private function interpolate(array $cumulative, array $time, int $index, float $target): float
    {
        $previous = $index - 1;
        $span = $cumulative[$index] - $cumulative[$previous];
        $fraction = $span > 0 ? ($target - $cumulative[$previous]) / $span : 0.0;
        $fraction = max(0.0, min(1.0, $fraction));

        return (float) $time[$previous] + $fraction * ((float) $time[$index] - (float) $time[$previous]);
    }

    /**
     * @param  list<mixed>  $heartrate
     * @return array{avg_hr?: int}
     */
    private function meanHeartRate(array $heartrate, int $from, int $to): array
    {
        $sum = 0.0;
        $count = 0;
        for ($i = $from; $i < $to; $i++) {
            if (isset($heartrate[$i])) {
                $sum += (float) $heartrate[$i];
                $count++;
            }
        }

        return $count > 0 ? ['avg_hr' => (int) round($sum / $count)] : [];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $splits
     * @return list<array<string, int|string>>
     */
    private function fromSplitsMetric(?array $splits): array
    {
        if ($splits === null) {
            return [];
        }

        $rows = [];
        foreach ($splits as $split) {
            $distance = (float) ($split['distance'] ?? 0);
            $elapsed = (float) ($split['elapsed_time'] ?? 0);
            if ($distance < self::FULL_KM_MIN_DISTANCE_M || $elapsed <= 0) {
                continue;
            }
            $rows[] = $this->row((int) ($split['split'] ?? 0), $elapsed, $distance) + $this->averages($split);
        }

        return $rows;
    }

    /**
     * @return array{km: int, pace: string, elapsed_sec: int, distance_m: int}
     */
    private function row(int $km, float $elapsedSec, float $distanceM): array
    {
        return [
            'km' => $km,
            'pace' => PaceFormatter::format(PaceCalculator::secPerKm($distanceM, $elapsedSec) ?? 0.0),
            'elapsed_sec' => (int) round($elapsedSec),
            'distance_m' => (int) round($distanceM),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{avg_hr?: int, avg_cadence_spm?: int}
     */
    private function averages(array $source): array
    {
        $averages = [];
        if (isset($source['average_heartrate'])) {
            $averages['avg_hr'] = (int) round((float) $source['average_heartrate']);
        }
        if (isset($source['average_cadence'])) {
            $averages['avg_cadence_spm'] = (int) round((float) $source['average_cadence'] * 2);
        }

        return $averages;
    }
}
