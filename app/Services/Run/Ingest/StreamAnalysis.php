<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Services\Run\Metrics\PaceFormatter;

class StreamAnalysis
{
    /** Activity is "stopped" when velocity drops below this (m/s). */
    private const float STOP_VELOCITY_MS = 0.5;

    /** Grade (%) at or above which a sample counts as climbing. */
    private const float CLIMB_GRADE_PCT = 3.0;

    /** Rolling window (seconds) for the steepest *sustained* grade. */
    private const int GRADE_WINDOW_SEC = 20;

    /** Best-effort window durations in seconds → label suffix. */
    private const array BEST_EFFORT_WINDOWS = [
        30 => '30s',
        60 => '1min',
        180 => '3min',
        300 => '5min',
        600 => '10min',
        1200 => '20min',
        1800 => '30min',
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
        $grade = $this->data($streams, 'grade_smooth');

        $summary = $this->bestEffortPaces($time, $velocity)
            + $this->elevation($altitude)
            + $this->timeInZones($time, $heartrate, $hrZones)
            + $this->paceVariability($velocity)
            + $this->stoppedTime($time, $velocity)
            + $this->decoupling($time, $heartrate, $velocity)
            + $this->cadenceDistribution($time, $cadence, $optimalCadenceSpm)
            + $this->grade($grade, $time, $velocity);

        if (is_array($splitsMetric) && $splitsMetric !== []) {
            $cadenceByKm = $this->perKmCadenceFromStream($time, $distance, $cadence);
            $perKm = $this->perKm($splitsMetric);
            if (isset($perKm['per_km'])) {
                $perKm['per_km'] = $this->attachStreamCadenceToRows($perKm['per_km'], $cadenceByKm);
            }
            $summary += $perKm
                + $this->partialSplit($splitsMetric, $cadenceByKm)
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
        // time[j]-time[i] >= targetSec. The window stops as soon as it crosses
        // targetSec, so the trailing segment [j-1, j] is the one that overshoots.
        // Trim that overshoot off the trailing edge proportionally so the credited
        // distance maps to exactly targetSec; on uniform 1 Hz sampling the
        // overshoot is zero and the value is unchanged.
        $bestDist = 0.0;
        $j = 0;
        $windowDist = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            while ($j < $n - 1 && ($time[$j] - $time[$i]) < $targetSec) {
                $windowDist += ((float) $velocity[$j]) * ((float) ($time[$j + 1] - $time[$j]));
                $j++;
            }
            if (($time[$j] - $time[$i]) >= $targetSec) {
                $overshoot = (float) ($time[$j] - $time[$i]) - $targetSec;
                $trailingDt = (float) ($time[$j] - $time[$j - 1]);
                $trimDist = $overshoot > 0 && $trailingDt > 0
                    ? min($overshoot, $trailingDt) * (float) $velocity[$j - 1]
                    : 0.0;
                $bestDist = max($bestDist, $windowDist - $trimDist);
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
     * Hill metrics from the grade_smooth stream: steepest sustained climb,
     * share of time spent climbing, and grade-adjusted pace (GAP).
     *
     * @param  list<float|int>  $grade  per-sample gradient in percent
     * @param  list<float|int>  $time
     * @param  list<float|int>  $velocity
     * @return array<string, string|float>
     */
    private function grade(array $grade, array $time, array $velocity): array
    {
        if (count($grade) < 2 || count($time) < 2) {
            return [];
        }

        $result = [];
        $maxGrade = $this->maxSustainedGrade($grade, $time);
        if ($maxGrade !== null) {
            $result['max_grade_pct'] = $maxGrade;
        }
        $climbPct = $this->climbTimePct($grade, $time);
        if ($climbPct !== null) {
            $result['climb_time_pct'] = $climbPct;
        }
        $gap = $this->gradeAdjustedPace($grade, $time, $velocity);
        if ($gap !== null) {
            $result['gap_pace'] = $gap;
        }

        return $result;
    }

    /**
     * Steepest grade sustained over a rolling ~20s window, in percent. A raw
     * per-sample max would just surface GPS spikes, so this is time-weighted
     * over the window using the same two-pointer idiom as bestEffortPace().
     *
     * @param  list<float|int>  $grade
     * @param  list<float|int>  $time
     */
    private function maxSustainedGrade(array $grade, array $time): ?float
    {
        $n = min(count($grade), count($time));
        $best = null;
        $j = 0;
        $wSum = 0.0;
        $tSum = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            while ($j < $n - 1 && ($time[$j] - $time[$i]) < self::GRADE_WINDOW_SEC) {
                $dt = (float) ($time[$j + 1] - $time[$j]);
                $wSum += (float) $grade[$j] * $dt;
                $tSum += $dt;
                $j++;
            }
            if ($tSum > 0) {
                $mean = $wSum / $tSum;
                if ($best === null || $mean > $best) {
                    $best = $mean;
                }
            }
            $dtI = (float) ($time[$i + 1] - $time[$i]);
            $wSum -= (float) $grade[$i] * $dtI;
            $tSum -= $dtI;
        }

        return $best !== null ? round($best, 1) : null;
    }

    /**
     * Share of recorded time spent climbing (grade >= CLIMB_GRADE_PCT), percent.
     *
     * @param  list<float|int>  $grade
     * @param  list<float|int>  $time
     */
    private function climbTimePct(array $grade, array $time): ?float
    {
        $n = min(count($grade), count($time) - 1);
        if ($n < 1) {
            return null;
        }
        $climb = 0.0;
        $total = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dt = (float) ($time[$i + 1] - $time[$i]);
            $total += $dt;
            if ((float) $grade[$i] >= self::CLIMB_GRADE_PCT) {
                $climb += $dt;
            }
        }

        return $total > 0 ? round($climb / $total * 100, 1) : null;
    }

    /**
     * Grade-adjusted pace (GAP): the flat pace the effort was worth, using
     * Minetti's cost-of-running curve normalised to flat = 1. Uphill costs more,
     * so the flat-equivalent distance grows and the pace comes out faster than raw.
     *
     * @param  list<float|int>  $grade
     * @param  list<float|int>  $time
     * @param  list<float|int>  $velocity
     */
    private function gradeAdjustedPace(array $grade, array $time, array $velocity): ?string
    {
        $n = min(count($grade), count($time) - 1, count($velocity));
        if ($n < 1) {
            return null;
        }
        $flatEquivDist = 0.0;
        $movingTime = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $v = (float) $velocity[$i];
            if ($v < self::STOP_VELOCITY_MS) {
                continue;
            }
            $dt = (float) ($time[$i + 1] - $time[$i]);
            $flatEquivDist += $v * $dt * $this->gradeCostFactor((float) $grade[$i] / 100);
            $movingTime += $dt;
        }
        if ($flatEquivDist <= 0) {
            return null;
        }

        return PaceFormatter::format($movingTime / ($flatEquivDist / 1000));
    }

    /**
     * Minetti (2002) metabolic cost of running as a function of gradient
     * (rise/run fraction), normalised so flat ground = 1. Clamped positive for
     * steep descents where the polynomial dips below zero outside its fitted range.
     */
    private function gradeCostFactor(float $i): float
    {
        $cost = 155.4 * $i ** 5 - 30.4 * $i ** 4 - 43.3 * $i ** 3 + 46.3 * $i ** 2 + 19.5 * $i + 3.6;

        return max($cost, 0.36) / 3.6;
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
            $moving = (float) ($split['moving_time'] ?? 0);
            if ($distance < 950 || $moving <= 0) {
                continue;
            }
            $paceSec = $moving / ($distance / 1000);
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
     * The trailing sub-km "sisa" segment as its own row, pace-normalized per km
     * from moving_time (same basis as full-km rows). Display/narrative-only: it
     * is never a full km, never crowned fastest, and never enters the aggregate
     * metrics. Carries no `km` field by design so the AI payload can't be nudged
     * into naming it "km N".
     *
     * Only the final split qualifies (Strava emits at most one leftover), and
     * only when 100 m <= distance < 950 m (slivers under 100 m are noise, matching
     * the demo seeder threshold).
     *
     * @param  array<int, array<string, mixed>>  $splits
     * @param  array<int, int>  $cadenceByKm  km index (1-based) → average spm
     * @return array{partial_split?: array<string, int|string>}
     */
    private function partialSplit(array $splits, array $cadenceByKm): array
    {
        $last = end($splits);
        if (! is_array($last)) {
            return [];
        }
        $distance = (float) ($last['distance'] ?? 0);
        $moving = (float) ($last['moving_time'] ?? 0);
        if ($distance >= 950 || $distance < 100 || $moving <= 0) {
            return [];
        }
        $paceSec = $moving / ($distance / 1000);
        $row = [
            'distance_m' => (int) round($distance),
            'pace' => PaceFormatter::format($paceSec),
        ];
        if (isset($last['average_heartrate'])) {
            $row['avg_hr'] = (int) round((float) $last['average_heartrate']);
        }
        if (isset($last['average_cadence'])) {
            $row['avg_cadence_spm'] = (int) round((float) $last['average_cadence'] * 2);
        }
        $km = (int) ($last['split'] ?? 0);
        if (! isset($row['avg_cadence_spm']) && isset($cadenceByKm[$km])) {
            $row['avg_cadence_spm'] = $cadenceByKm[$km];
        }

        return ['partial_split' => $row];
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
        $last = $full[array_key_last($full)]['average_heartrate'] ?? null;
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
        $last = $full[array_key_last($full)]['average_cadence'] ?? null;
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

        // Require a meaningful margin (second half ≥1.5% faster). A bare `>` lets
        // a flat run coin-flip into "negative split" on per-km noise alone.
        return ['negative_split' => $secondAvg > $firstAvg * 1.015];
    }

}
