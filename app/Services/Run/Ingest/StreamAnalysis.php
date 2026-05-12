<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Services\Run\Metrics\PaceFormatter;

/**
 * Pure stream-math. Converts Strava's per-second arrays into a compact
 * summary blob: HR zones, decoupling, cadence distribution, best-effort
 * paces, pace variability, stopped time, hr drift, negative split.
 *
 * Output is the JSON we'll persist as `activity_details.stream_summary`.
 *
 * Lifted in spirit from openclaw run-tracker's StreamSummaryService with
 * fixes baked in:
 *   - VDOT is NOT computed here — that lives in PrService and only fires
 *     against confirmed PRs (race times or 30+ min hard efforts).
 *   - Decoupling is captured as a raw percentage; no alerting threshold
 *     in v1 (saturated by heat in tropical climates).
 */
class StreamAnalysis
{
    /** Activity is "stopped" when velocity drops below this (m/s). */
    private const float STOP_VELOCITY_MS = 0.5;

    /** Best-effort window durations in seconds → label suffix. */
    private const array BEST_EFFORT_WINDOWS = [
        30 => '30s',
        60 => '1min',
        180 => '3min',
        300 => '5min',
        600 => '10min',
        1200 => '20min',
        3600 => '60min',
    ];

    /**
     * @param  array<string, mixed>  $streams  raw Strava streams dict
     * @param  array<string, array{lo: int, hi: int}>  $hrZones  inclusive lo / exclusive hi
     * @param  array<int, array<string, mixed>>|null  $splitsMetric  per-km splits from /activities/{id}
     * @return array<string, mixed>
     */
    public function compute(array $streams, array $hrZones, ?array $splitsMetric, int $optimalCadenceSpm): array
    {
        $time = $this->data($streams, 'time');
        $heartrate = $this->data($streams, 'heartrate');
        $velocity = $this->data($streams, 'velocity_smooth');
        $cadence = $this->data($streams, 'cadence');
        $altitude = $this->data($streams, 'altitude');
        $distance = $this->data($streams, 'distance');

        $summary = $this->bestEffortPaces($time, $velocity)
            + $this->elevation($altitude)
            + $this->timeInZones($time, $heartrate, $hrZones)
            + $this->paceVariability($velocity)
            + $this->stoppedTime($time, $velocity)
            + $this->decoupling($time, $heartrate, $velocity)
            + $this->cadenceDistribution($time, $cadence, $optimalCadenceSpm);

        if (is_array($splitsMetric) && $splitsMetric !== []) {
            $perKm = $this->perKm($splitsMetric);
            if (isset($perKm['per_km'])) {
                $perKm['per_km'] = $this->attachStreamCadenceToRows(
                    $perKm['per_km'],
                    $this->perKmCadenceFromStream($time, $distance, $cadence),
                );
            }
            $summary += $perKm
                + $this->hrDriftFromSplits($splitsMetric)
                + $this->cadenceDropFromSplits($splitsMetric)
                + $this->negativeSplit($splitsMetric);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $streams
     * @return list<float|int>
     */
    private function data(array $streams, string $key): array
    {
        $payload = $streams[$key] ?? null;
        if (! is_array($payload)) {
            return [];
        }

        $data = $payload['data'] ?? $payload;

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * @param  list<float|int>  $time
     * @param  list<float|int>  $velocity
     * @return array<string, string|float|null>
     */
    private function bestEffortPaces(array $time, array $velocity): array
    {
        if ($time === [] || $velocity === []) {
            return [];
        }
        $result = [];
        foreach (self::BEST_EFFORT_WINDOWS as $sec => $label) {
            $pace = $this->bestEffortPace($time, $velocity, $sec);
            if ($pace !== null) {
                $result["best_{$label}_pace"] = $pace;
            }
        }

        return $result;
    }

    /**
     * Returns the fastest pace (M:SS / km) sustained over $targetSec consecutive
     * seconds, or null if the run wasn't long enough.
     *
     * @param  list<float|int>  $time
     * @param  list<float|int>  $velocity
     */
    public function bestEffortPace(array $time, array $velocity, int $targetSec): ?string
    {
        $n = count($time);
        if ($n < 2 || count($velocity) < $n) {
            return null;
        }
        $totalTime = (float) ($time[$n - 1] - $time[0]);
        if ($totalTime < $targetSec * 0.95) {
            return null;
        }

        // Two-pointer sliding window: distance covered between i..j where
        // time[j]-time[i] >= targetSec.
        $bestDist = 0.0;
        $j = 0;
        $windowDist = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            while ($j < $n - 1 && ($time[$j] - $time[$i]) < $targetSec) {
                $windowDist += ((float) $velocity[$j]) * ((float) ($time[$j + 1] - $time[$j]));
                $j++;
            }
            if (($time[$j] - $time[$i]) >= $targetSec) {
                $bestDist = max($bestDist, $windowDist);
            }
            $windowDist -= ((float) $velocity[$i]) * ((float) ($time[$i + 1] - $time[$i]));
        }
        if ($bestDist <= 0) {
            return null;
        }

        return PaceFormatter::format($targetSec / ($bestDist / 1000));
    }

    /**
     * @param  list<float|int>  $altitude
     * @return array<string, int>
     */
    private function elevation(array $altitude): array
    {
        $n = count($altitude);
        if ($n < 2) {
            return [];
        }
        $ascent = 0.0;
        $descent = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $delta = (float) $altitude[$i] - (float) $altitude[$i - 1];
            if ($delta > 0) {
                $ascent += $delta;
            } else {
                $descent += abs($delta);
            }
        }

        return ['ascent_m' => (int) round($ascent), 'descent_m' => (int) round($descent)];
    }

    /**
     * @param  list<float|int>  $time
     * @param  list<float|int>  $heartrate
     * @param  array<string, array{lo: int, hi: int}>  $hrZones
     * @return array<string, array<string, float>>
     */
    private function timeInZones(array $time, array $heartrate, array $hrZones): array
    {
        if ($time === [] || $heartrate === []) {
            return [];
        }
        $zoneSec = array_fill_keys(array_keys($hrZones), 0.0);
        $total = 0.0;
        $n = min(count($time) - 1, count($heartrate));
        for ($i = 0; $i < $n; $i++) {
            $dt = (float) $time[$i + 1] - (float) $time[$i];
            $bpm = (float) $heartrate[$i];
            foreach ($hrZones as $name => $range) {
                if ($bpm >= $range['lo'] && $bpm < $range['hi']) {
                    $zoneSec[$name] += $dt;
                    $total += $dt;

                    break;
                }
            }
        }
        if ($total <= 0) {
            return [];
        }
        $minutes = [];
        $percent = [];
        foreach ($zoneSec as $z => $s) {
            $minutes[$z] = round($s / 60, 1);
            $percent[$z] = round($s / $total * 100, 1);
        }

        return ['time_in_zone_min' => $minutes, 'time_in_zone_pct' => $percent];
    }

    /**
     * @param  list<float|int>  $velocity
     * @return array<string, float>
     */
    private function paceVariability(array $velocity): array
    {
        $paces = [];
        foreach ($velocity as $v) {
            if ((float) $v > self::STOP_VELOCITY_MS) {
                $paces[] = 1000 / (float) $v;
            }
        }
        if (count($paces) < 2) {
            return [];
        }
        $mean = array_sum($paces) / count($paces);
        $variance = array_sum(array_map(fn (float $p): float => ($p - $mean) ** 2, $paces)) / count($paces);

        return ['pace_variability_sec' => round(sqrt($variance), 1)];
    }

    /**
     * @param  list<float|int>  $time
     * @param  list<float|int>  $velocity
     * @return array<string, int|float>
     */
    private function stoppedTime(array $time, array $velocity): array
    {
        if (count($time) < 2) {
            return [];
        }
        $stopped = 0.0;
        $count = 0;
        $inStop = false;
        $n = min(count($velocity), count($time) - 1);
        for ($i = 0; $i < $n; $i++) {
            $dt = (float) $time[$i + 1] - (float) $time[$i];
            if ((float) $velocity[$i] < self::STOP_VELOCITY_MS) {
                $stopped += $dt;
                if (! $inStop) {
                    $count++;
                    $inStop = true;
                }
            } else {
                $inStop = false;
            }
        }

        return $stopped > 0 ? ['stopped_time_sec' => (int) round($stopped), 'stop_count' => $count] : [];
    }

    /**
     * Cardiac decoupling: ratio of average (HR / pace) in the second half
     * vs the first half. Positive = HR drifted up for the same pace.
     *
     * @param  list<float|int>  $time
     * @param  list<float|int>  $heartrate
     * @param  list<float|int>  $velocity
     * @return array<string, float>
     */
    private function decoupling(array $time, array $heartrate, array $velocity): array
    {
        $n = min(count($time), count($heartrate), count($velocity));
        $half = (int) ($n / 2);
        if ($half < 2) {
            return [];
        }
        $avg = function (int $from, int $to) use ($heartrate, $velocity): ?array {
            $hSum = 0.0;
            $pSum = 0.0;
            $c = 0;
            for ($i = $from; $i < $to; $i++) {
                $v = (float) ($velocity[$i] ?? 0);
                if ($v < self::STOP_VELOCITY_MS) {
                    continue;
                }
                $hSum += (float) ($heartrate[$i] ?? 0);
                $pSum += 1000 / $v;
                $c++;
            }
            if ($c === 0) {
                return null;
            }

            return [$hSum / $c, $pSum / $c];
        };
        $first = $avg(0, $half);
        $second = $avg($half, $n);
        if ($first === null || $second === null || $first[1] <= 0 || $second[1] <= 0) {
            return [];
        }
        $firstRatio = $first[0] / $first[1];
        $secondRatio = $second[0] / $second[1];
        $pct = round(($secondRatio / $firstRatio - 1) * 100, 1);

        return ['decoupling_pct' => $pct];
    }

    /**
     * Cadence stream is "rotations per minute, single foot". Double it for
     * the conventional steps-per-minute (SPM) used in running.
     *
     * @param  list<float|int>  $time
     * @param  list<float|int>  $cadence
     * @return array<string, mixed>
     */
    private function cadenceDistribution(array $time, array $cadence, int $optimalSpm): array
    {
        if ($time === [] || $cadence === []) {
            return [];
        }
        $buckets = ['<165' => 0.0, '165-175' => 0.0, '>175' => 0.0];
        $total = 0.0;
        $optimalLo = $optimalSpm;
        $optimalHi = $optimalSpm + 15;
        $optSec = 0.0;
        $n = min(count($cadence), count($time) - 1);
        for ($i = 0; $i < $n; $i++) {
            $dt = (float) $time[$i + 1] - (float) $time[$i];
            $spm = (float) $cadence[$i] * 2;
            if ($spm < 165) {
                $buckets['<165'] += $dt;
            } elseif ($spm <= 175) {
                $buckets['165-175'] += $dt;
            } else {
                $buckets['>175'] += $dt;
            }
            if ($spm >= $optimalLo && $spm <= $optimalHi) {
                $optSec += $dt;
            }
            $total += $dt;
        }
        if ($total <= 0) {
            return [];
        }

        return [
            'cadence_distribution_pct' => [
                '<165' => round($buckets['<165'] / $total * 100, 1),
                '165-175' => round($buckets['165-175'] / $total * 100, 1),
                '>175' => round($buckets['>175'] / $total * 100, 1),
            ],
            'optimal_cadence_pct' => round($optSec / $total * 100, 1),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $splits
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function perKm(array $splits): array
    {
        $perKm = [];
        foreach ($splits as $split) {
            $distance = (float) ($split['distance'] ?? 0);
            $elapsed = (float) ($split['elapsed_time'] ?? 0);
            if ($distance < 950 || $elapsed <= 0) {
                continue;
            }
            $paceSec = $elapsed / ($distance / 1000);
            $row = [
                'km' => (int) ($split['split'] ?? 0),
                'pace' => PaceFormatter::format($paceSec),
            ];
            if (isset($split['average_heartrate'])) {
                $row['avg_hr'] = (int) round((float) $split['average_heartrate']);
            }
            if (isset($split['average_cadence'])) {
                $row['avg_cadence_spm'] = (int) round((float) $split['average_cadence'] * 2);
            }
            $perKm[] = $row;
        }

        return $perKm === [] ? [] : ['per_km' => $perKm];
    }

    /**
     * Bucket the cadence stream by km using cumulative distance from the
     * `distance` stream. Strava's `splits_metric` payload doesn't carry
     * cadence, so this is the only way to populate per-km cadence for the
     * /runs/{id} splits table.
     *
     * Time-weighted (matching `cadenceDistribution()` line 317) and doubles
     * the half-cadence values Strava ships in the stream.
     *
     * @param  list<float|int>  $time
     * @param  list<float|int>  $distance
     * @param  list<float|int>  $cadence
     * @return array<int, int>  km index (1-based) → average spm
     */
    private function perKmCadenceFromStream(array $time, array $distance, array $cadence): array
    {
        if ($time === [] || $distance === [] || $cadence === []) {
            return [];
        }
        $n = min(count($cadence), count($distance), count($time) - 1);
        if ($n <= 0) {
            return [];
        }

        /** @var array<int, array{sum: float, dt: float}> $buckets */
        $buckets = [];
        for ($i = 0; $i < $n; $i++) {
            $dt = (float) $time[$i + 1] - (float) $time[$i];
            if ($dt <= 0) {
                continue;
            }
            $km = ((int) floor((float) $distance[$i] / 1000)) + 1;
            $spm = (float) $cadence[$i] * 2;
            $buckets[$km] ??= ['sum' => 0.0, 'dt' => 0.0];
            $buckets[$km]['sum'] += $spm * $dt;
            $buckets[$km]['dt'] += $dt;
        }

        $result = [];
        foreach ($buckets as $km => $bucket) {
            if ($bucket['dt'] > 0) {
                $result[$km] = (int) round($bucket['sum'] / $bucket['dt']);
            }
        }

        return $result;
    }

    /**
     * Decorate `per_km` rows with `avg_cadence_spm` from the per-km cadence
     * map. Existing values (from a future Strava payload that adds cadence
     * to splits_metric) are preserved.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $cadenceByKm
     * @return array<int, array<string, mixed>>
     */
    private function attachStreamCadenceToRows(array $rows, array $cadenceByKm): array
    {
        if ($cadenceByKm === []) {
            return $rows;
        }
        foreach ($rows as $i => $row) {
            if (isset($row['avg_cadence_spm'])) {
                continue;
            }
            $km = (int) ($row['km'] ?? 0);
            if (isset($cadenceByKm[$km])) {
                $rows[$i]['avg_cadence_spm'] = $cadenceByKm[$km];
            }
        }

        return $rows;
    }

    /**
     * HR drift across the run: avg HR of last full-km split minus first.
     *
     * @param  array<int, array<string, mixed>>  $splits
     * @return array<string, float>
     */
    private function hrDriftFromSplits(array $splits): array
    {
        $full = array_values(array_filter($splits, fn (array $s): bool => (float) ($s['distance'] ?? 0) >= 950));
        if (count($full) < 2) {
            return [];
        }
        $first = $full[0]['average_heartrate'] ?? null;
        $last = end($full)['average_heartrate'] ?? null;
        if ($first === null || $last === null) {
            return [];
        }

        return ['hr_drift_bpm' => round((float) $last - (float) $first, 1)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $splits
     * @return array<string, float>
     */
    private function cadenceDropFromSplits(array $splits): array
    {
        $full = array_values(array_filter($splits, fn (array $s): bool => (float) ($s['distance'] ?? 0) >= 950));
        if (count($full) < 2) {
            return [];
        }
        $first = $full[0]['average_cadence'] ?? null;
        $last = end($full)['average_cadence'] ?? null;
        if ($first === null || $last === null) {
            return [];
        }

        // cadence_drop_spm: how much SPM dropped from start to end (positive = slowed)
        return ['cadence_drop_spm' => round(((float) $first - (float) $last) * 2, 1)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $splits
     * @return array<string, bool>
     */
    private function negativeSplit(array $splits): array
    {
        $full = array_values(array_filter($splits, fn (array $s): bool => (float) ($s['distance'] ?? 0) >= 950));
        if (count($full) < 2) {
            return [];
        }
        $half = (int) ceil(count($full) / 2);
        $firstHalf = array_slice($full, 0, $half);
        $secondHalf = array_slice($full, $half);
        $firstAvg = array_sum(array_column($firstHalf, 'average_speed')) / count($firstHalf);
        $secondAvg = array_sum(array_column($secondHalf, 'average_speed')) / count($secondHalf);

        return ['negative_split' => $secondAvg > $firstAvg];
    }

}
