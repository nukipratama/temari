<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Services\Run\Metrics\IntervalDetector;
use App\Services\Run\Metrics\PaceFormatter;

class StreamAnalysis
{
    /** Activity is "stopped" when velocity drops below this (m/s). */
    private const float STOP_VELOCITY_MS = 0.5;

    /** Grade (%) at or above which a sample counts as climbing. */
    private const float CLIMB_GRADE_PCT = 3.0;

    /**
     * Steepest sustained grade past which neither decoupling nor HR drift is
     * published at all. The Minetti cost curve behind grade adjustment is
     * fitted for ordinary running gradients and degrades outside them, so on a
     * genuinely steep run the corrected figure is not trustworthy either — and
     * a number nobody can rely on is worse than no number, since the plan reads
     * this to decide whether an athlete faded.
     */
    private const float TERRAIN_UNREADABLE_GRADE_PCT = 15.0;

    /** Rolling window (seconds) for the steepest *sustained* grade. */
    private const int GRADE_WINDOW_SEC = 20;

    /** Distance (m) at or above which a Strava split counts as a full kilometre. */
    private const float FULL_KM_MIN_DISTANCE_M = 950;

    /** Distance (m) below which the trailing "sisa" segment is discarded as noise. */
    private const float PARTIAL_SPLIT_MIN_DISTANCE_M = 100;

    private const int DRIFT_METRIC_VERSION = 2;

    private const int DRIFT_MIN_SEGMENT_MOVING_SEC = 1200;

    private const float DRIFT_WARMUP_MOVING_TIME_FRACTION = 0.1;

    /**
     * Aerobic decoupling compares HR/pace drift between halves, which only
     * describes physiology over a sustained steady effort. Below this the split
     * halves are too short for the ratio to mean anything and it reports GPS and
     * traffic noise as cardiac drift, so the key is omitted entirely.
     */
    private const int DECOUPLING_MIN_MOVING_SEC = 2700;

    /**
     * Heart rates outside this band are a strap dropout or a contact spike, not
     * an athlete. Samples carrying one are skipped rather than averaged in: a
     * stretch of dropouts in one half drags that half's mean HR toward zero and
     * the ratio between the halves then reports the gap in the data as drift.
     *
     * Deliberately a fixed physiological band rather than the runner's own zone
     * table, which {@see self::timeInZones} uses for the same "skip what does
     * not belong" purpose: a zone table's Z1 floor sits around 100 bpm, so
     * reusing it would discard genuine easy-running samples.
     */
    private const float HR_PLAUSIBLE_MIN_BPM = 30.0;

    private const float HR_PLAUSIBLE_MAX_BPM = 220.0;

    /**
     * Drift this far from zero is not a reading about the athlete. Real aerobic
     * decoupling lives in single digits and a hard long run reaches the teens;
     * a figure past this describes inputs that broke in a way the per-sample
     * guards did not catch, so no number is published rather than a wrong one.
     */
    private const float DECOUPLING_IMPLAUSIBLE_PCT = 40.0;

    /**
     * How much faster the second half must average before a run counts as a
     * negative split. A bare `>` lets per-km noise coin-flip into the badge;
     * fitted against real split data, 7% lands it near 1 run in 8, so finishing
     * strong stays a deliberate act rather than the default outcome.
     */
    private const float NEGATIVE_SPLIT_MARGIN = 1.07;

    /** The zone whose lower bound is the easy-effort heart-rate cap: the top of Z2. */
    public const string EASY_CAP_ZONE = 'Z3';

    public const int EASY_CAP_WARMUP_SEC = 300;

    public const int EASY_CAP_ROLLING_SEC = 30;

    public const int EASY_CAP_MARGIN_BPM = 5;

    /** Best-effort window durations in seconds → label suffix. */
    public const array BEST_EFFORT_WINDOWS = [
        30 => '30s',
        60 => '1min',
        180 => '3min',
        300 => '5min',
        600 => '10min',
        1200 => '20min',
        1800 => '30min',
        3600 => '60min',
    ];

    public function __construct(private readonly KmSplitBuilder $kmSplits)
    {
    }

    /**
     * @param  array<string, mixed>  $streams  raw Strava streams dict
     * @param  array<string, array{lo: int, hi: int}>  $hrZones  inclusive lo / exclusive hi
     * @param  array<int, array<string, mixed>>|null  $splitsMetric  per-km splits from /activities/{id}
     * @param  float|null  $deviceDistanceM  the activity's own total distance
     * @param  array<int, array<string, mixed>>|null  $laps  Strava `laps[]` as ingested
     * @return array<string, mixed>
     */
    public function compute(
        array $streams,
        array $hrZones,
        ?array $splitsMetric,
        int $optimalCadenceSpm,
        ?float $deviceDistanceM = null,
        ?array $laps = null,
    ): array {
        $time = $this->data($streams, 'time');
        $heartrate = $this->data($streams, 'heartrate');
        $velocity = $this->data($streams, 'velocity_smooth');
        $cadence = $this->data($streams, 'cadence');
        $altitude = $this->data($streams, 'altitude');
        $distance = $this->data($streams, 'distance');
        $grade = $this->data($streams, 'grade_smooth');
        $latlng = $this->data($streams, 'latlng');

        $seconds = self::floats($time);
        $intervals = self::intervals($seconds);
        $grade = self::floats($grade);
        $gradeCost = array_map(fn (float $pct): float => $this->gradeCostFactor($pct / 100), $grade);

        $summary = array_merge(
            $this->bestEffortPaces($seconds, $intervals, $velocity),
            $this->elevation($altitude),
            $this->timeInZones($intervals, $heartrate, $hrZones),
            $this->easyCapOverage($streams, $hrZones),
            $this->stoppedTime($intervals, $velocity),
            $this->cadenceDistribution($intervals, $cadence, $optimalCadenceSpm),
            $this->grade($grade, $gradeCost, $seconds, $intervals, $velocity),
        );

        $terrainReadable = self::terrainIsReadable($summary);

        $summary = array_merge($summary, $this->steadyEffortDrift($splitsMetric ?? []));

        if ($terrainReadable && $this->isSustainedEffort($time, $heartrate, $velocity, $grade, $summary)) {
            $summary = array_merge($summary, $this->decoupling($seconds, $intervals, $heartrate, $velocity, $gradeCost));
        }

        $cadenceByKm = $this->perKmCadenceFromStream($intervals, $distance, $cadence);

        $perKm = $this->kmSplits->perKm($laps, $latlng, $time, $heartrate, $splitsMetric, $deviceDistanceM);
        if ($perKm !== []) {
            $summary['per_km'] = $this->attachStreamCadenceToRows($perKm, $cadenceByKm);
        }

        $lapRows = $this->kmSplits->laps($laps);
        if ($lapRows !== []) {
            $summary['laps'] = $lapRows;
        }

        if (is_array($splitsMetric) && $splitsMetric !== []) {
            $summary = array_merge(
                $summary,
                $this->partialSplit($splitsMetric, $cadenceByKm),
                // HR drift is first-km against last-km average HR, with no
                // grade term available at split granularity — so unlike
                // decoupling it cannot be corrected, only withheld.
                $terrainReadable ? $this->hrDriftFromSplits($splitsMetric) : [],
                $this->cadenceDropFromSplits($splitsMetric),
                $this->negativeSplit($splitsMetric),
                $this->paceVariability($splitsMetric),
            );
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
     * @param  list<float|int>  $values
     * @return list<float>
     */
    private static function floats(array $values): array
    {
        $floats = [];
        foreach ($values as $value) {
            $floats[] = (float) $value;
        }

        return $floats;
    }

    /**
     * Seconds between each sample and the next.
     *
     * @param  list<float>  $seconds
     * @return list<float>
     */
    private static function intervals(array $seconds): array
    {
        $intervals = [];
        for ($i = 1, $n = count($seconds); $i < $n; $i++) {
            $intervals[] = $seconds[$i] - $seconds[$i - 1];
        }

        return $intervals;
    }

    /**
     * @param  list<float>  $seconds
     * @param  list<float>  $intervals
     * @param  list<float|int>  $velocity
     * @return array<string, string|float|null>
     */
    private function bestEffortPaces(array $seconds, array $intervals, array $velocity): array
    {
        $series = self::effortSeries($seconds, $intervals, $velocity);
        if ($series === null) {
            return [];
        }
        $result = [];
        foreach (self::BEST_EFFORT_WINDOWS as $sec => $label) {
            $pace = self::fastestWindowPace(...$series, targetSec: $sec);
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
        $seconds = self::floats($time);
        $series = self::effortSeries($seconds, self::intervals($seconds), $velocity);

        return $series === null ? null : self::fastestWindowPace(...$series, targetSec: $targetSec);
    }

    /**
     * @param  list<float>  $seconds
     * @param  list<float>  $intervals
     * @param  list<float|int>  $velocity
     * @return array{time: list<float>, velocity: list<float>, segmentDist: list<float>}|null
     */
    private static function effortSeries(array $seconds, array $intervals, array $velocity): ?array
    {
        $n = count($seconds);
        if ($n < 2 || count($velocity) < $n) {
            return null;
        }

        $v = self::floats(array_slice($velocity, 0, $n));
        $segmentDist = [];
        foreach ($intervals as $i => $interval) {
            $segmentDist[] = $v[$i] * $interval;
        }

        return ['time' => $seconds, 'velocity' => $v, 'segmentDist' => $segmentDist];
    }

    /**
     * @param  list<float>  $time
     * @param  list<float>  $velocity
     * @param  list<float>  $segmentDist  distance covered between sample i and i + 1
     */
    private static function fastestWindowPace(array $time, array $velocity, array $segmentDist, int $targetSec): ?string
    {
        $last = count($time) - 1;
        if ($time[$last] - $time[0] < $targetSec * 0.95) {
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
        for ($i = 0; $i < $last; $i++) {
            $start = $time[$i];
            $span = $time[$j] - $start;
            while ($j < $last && $span < $targetSec) {
                $windowDist += $segmentDist[$j];
                $span = $time[++$j] - $start;
            }
            if ($span >= $targetSec) {
                $overshoot = $span - $targetSec;
                $credited = $windowDist;
                if ($overshoot > 0) {
                    $trailingDt = $time[$j] - $time[$j - 1];
                    if ($trailingDt > 0) {
                        $credited -= min($overshoot, $trailingDt) * $velocity[$j - 1];
                    }
                }
                if ($credited > $bestDist) {
                    $bestDist = $credited;
                }
            }
            $windowDist -= $segmentDist[$i];
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
        $descent = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $delta = (float) $altitude[$i] - (float) $altitude[$i - 1];
            if ($delta < 0) {
                $descent += abs($delta);
            }
        }

        return ['descent_m' => (int) round($descent)];
    }

    /**
     * Hill metrics from the grade_smooth stream: steepest sustained climb,
     * share of time spent climbing, and grade-adjusted pace (GAP).
     *
     * @param  list<float>  $grade  per-sample gradient in percent
     * @param  list<float>  $gradeCost  per-sample {@see self::gradeCostFactor()}
     * @param  list<float>  $seconds
     * @param  list<float>  $intervals
     * @param  list<float|int>  $velocity
     * @return array<string, string|float>
     */
    private function grade(array $grade, array $gradeCost, array $seconds, array $intervals, array $velocity): array
    {
        if (count($grade) < 2 || count($seconds) < 2) {
            return [];
        }

        $result = [];
        $maxGrade = $this->maxSustainedGrade($grade, $seconds, $intervals);
        if ($maxGrade !== null) {
            $result['max_grade_pct'] = $maxGrade;
        }
        $climbPct = $this->climbTimePct($grade, $intervals);
        if ($climbPct !== null) {
            $result['climb_time_pct'] = $climbPct;
        }
        $gap = $this->gradeAdjustedPace($gradeCost, $intervals, $velocity);
        if ($gap !== null) {
            $result['gap_pace'] = $gap;
        }

        return $result;
    }

    /**
     * Whether this run's terrain is gentle enough for a heart-rate-against-pace
     * reading to mean anything. Reads the max sustained grade `grade()` has
     * already computed, so it costs nothing; a run with no grade stream is
     * treated as readable rather than silently losing both metrics.
     *
     * @param  array<string, mixed>  $summary
     */
    private static function terrainIsReadable(array $summary): bool
    {
        $maxGrade = $summary['max_grade_pct'] ?? null;

        return ! is_numeric($maxGrade) || abs((float) $maxGrade) < self::TERRAIN_UNREADABLE_GRADE_PCT;
    }

    /**
     * Steepest grade sustained over a rolling ~20s window, in percent. A raw
     * per-sample max would just surface GPS spikes, so this is time-weighted
     * over the window using the same two-pointer idiom as bestEffortPace().
     *
     * @param  list<float>  $grade
     * @param  list<float>  $seconds
     * @param  list<float>  $intervals
     */
    private function maxSustainedGrade(array $grade, array $seconds, array $intervals): ?float
    {
        $n = min(count($grade), count($seconds));
        $best = null;
        $j = 0;
        $wSum = 0.0;
        $tSum = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            $start = $seconds[$i];
            while ($j < $n - 1 && $seconds[$j] - $start < self::GRADE_WINDOW_SEC) {
                $wSum += $grade[$j] * $intervals[$j];
                $tSum += $intervals[$j];
                $j++;
            }
            if ($tSum > 0) {
                $mean = $wSum / $tSum;
                if ($best === null || $mean > $best) {
                    $best = $mean;
                }
            }
            $wSum -= $grade[$i] * $intervals[$i];
            $tSum -= $intervals[$i];
        }

        return $best !== null ? round($best, 1) : null;
    }

    /**
     * Share of recorded time spent climbing (grade >= CLIMB_GRADE_PCT), percent.
     *
     * @param  list<float>  $grade
     * @param  list<float>  $intervals
     */
    private function climbTimePct(array $grade, array $intervals): ?float
    {
        $n = min(count($grade), count($intervals));
        if ($n < 1) {
            return null;
        }
        $climb = 0.0;
        $total = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $total += $intervals[$i];
            if ($grade[$i] >= self::CLIMB_GRADE_PCT) {
                $climb += $intervals[$i];
            }
        }

        return $total > 0 ? round($climb / $total * 100, 1) : null;
    }

    /**
     * Grade-adjusted pace (GAP): the flat pace the effort was worth, using
     * Minetti's cost-of-running curve normalised to flat = 1. Uphill costs more,
     * so the flat-equivalent distance grows and the pace comes out faster than raw.
     *
     * @param  list<float>  $gradeCost  per-sample {@see self::gradeCostFactor()}
     * @param  list<float>  $intervals
     * @param  list<float|int>  $velocity
     */
    private function gradeAdjustedPace(array $gradeCost, array $intervals, array $velocity): ?string
    {
        $n = min(count($gradeCost), count($intervals), count($velocity));
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
            $flatEquivDist += $v * $intervals[$i] * $gradeCost[$i];
            $movingTime += $intervals[$i];
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
     * Moving seconds after the first {@see self::EASY_CAP_WARMUP_SEC} whose
     * trailing {@see self::EASY_CAP_ROLLING_SEC} average heart rate sat more
     * than {@see self::EASY_CAP_MARGIN_BPM} above the easy cap, with the cap
     * it was measured against. Empty without a usable heart-rate stream.
     *
     * @param  array<string, mixed>  $streams  raw Strava streams dict
     * @param  array<string, array{lo: int, hi: int}>  $hrZones
     * @return array{easy_cap_bpm?: int, over_easy_cap_sec?: int}
     */
    public function easyCapOverage(array $streams, array $hrZones): array
    {
        $cap = $hrZones[self::EASY_CAP_ZONE]['lo'] ?? null;
        $seconds = self::floats($this->data($streams, 'time'));
        $heartrate = self::floats($this->data($streams, 'heartrate'));
        $velocity = $this->data($streams, 'velocity_smooth');
        $n = min(count($seconds), count($heartrate));
        if ($cap === null || $n < 2) {
            return [];
        }

        $over = 0.0;
        $plausible = 0;
        $windowStart = 0;
        $windowSum = 0.0;
        $windowCount = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($heartrate[$i] >= self::HR_PLAUSIBLE_MIN_BPM && $heartrate[$i] <= self::HR_PLAUSIBLE_MAX_BPM) {
                $windowSum += $heartrate[$i];
                $windowCount++;
                $plausible++;
            }
            while ($seconds[$i] - $seconds[$windowStart] >= self::EASY_CAP_ROLLING_SEC) {
                if ($heartrate[$windowStart] >= self::HR_PLAUSIBLE_MIN_BPM && $heartrate[$windowStart] <= self::HR_PLAUSIBLE_MAX_BPM) {
                    $windowSum -= $heartrate[$windowStart];
                    $windowCount--;
                }
                $windowStart++;
            }

            $moving = ! isset($velocity[$i]) || (float) $velocity[$i] >= self::STOP_VELOCITY_MS;
            if ($i === $n - 1 || $windowCount === 0 || ! $moving || $seconds[$i] - $seconds[0] < self::EASY_CAP_WARMUP_SEC) {
                continue;
            }
            if ($windowSum / $windowCount > $cap + self::EASY_CAP_MARGIN_BPM) {
                $over += $seconds[$i + 1] - $seconds[$i];
            }
        }

        return $plausible === 0 ? [] : ['easy_cap_bpm' => (int) $cap, 'over_easy_cap_sec' => (int) round($over)];
    }

    /**
     * @param  list<float>  $intervals
     * @param  list<float|int>  $heartrate
     * @param  array<string, array{lo: int, hi: int}>  $hrZones
     * @return array<string, array<string, float>>
     */
    private function timeInZones(array $intervals, array $heartrate, array $hrZones): array
    {
        if ($intervals === [] || $heartrate === []) {
            return [];
        }
        $zoneSec = array_fill_keys(array_keys($hrZones), 0.0);
        $total = 0.0;
        $n = min(count($intervals), count($heartrate));
        for ($i = 0; $i < $n; $i++) {
            $bpm = (float) $heartrate[$i];
            foreach ($hrZones as $name => $range) {
                if ($bpm >= $range['lo'] && $bpm < $range['hi']) {
                    $zoneSec[$name] += $intervals[$i];
                    $total += $intervals[$i];

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
     * Pace in sec/km for a split that covers a full kilometre, or null when the
     * split is a trailing sliver or carries no usable time. The 950 m floor is
     * what separates a real kilometre from Strava's leftover final segment.
     *
     * @param  array<string, mixed>  $split
     */
    private function fullKmPaceSec(array $split): ?float
    {
        $distance = (float) ($split['distance'] ?? 0);
        $moving = (float) ($split['moving_time'] ?? 0);

        if ($distance < self::FULL_KM_MIN_DISTANCE_M || $moving <= 0) {
            return null;
        }

        return $moving / ($distance / 1000);
    }

    /**
     * @param  array<int, array<string, mixed>>  $splits
     * @return list<array<string, mixed>>
     */
    private function fullKmSplits(array $splits): array
    {
        return array_values(array_filter(
            $splits,
            fn (array $s): bool => (float) ($s['distance'] ?? 0) >= self::FULL_KM_MIN_DISTANCE_M,
        ));
    }

    /**
     * Version 2 cardiac drift compares the two halves of the longest steady
     * full-kilometre segment after the fixed warm-up exclusion.
     *
     * @param  array<int, array<string, mixed>>  $splits
     * @return array{drift_metric_version: int, steady_effort_decoupling_pct: float|null, steady_effort_hr_drift_bpm: float|null}
     */
    private function steadyEffortDrift(array $splits): array
    {
        $full = $this->fullKmSplits($splits);
        $warmupTarget = self::movingTimeSec($splits) * self::DRIFT_WARMUP_MOVING_TIME_FRACTION;
        $warmupTime = 0.0;
        $warmupSplits = 0;
        foreach ($full as $split) {
            if ($warmupSplits >= 1 && $warmupTime >= $warmupTarget) {
                break;
            }
            $warmupTime += max(0.0, (float) ($split['moving_time'] ?? 0));
            $warmupSplits++;
        }

        $segments = [];
        $segment = [];
        $previousPace = null;
        foreach (array_slice($full, $warmupSplits) as $split) {
            $pace = $this->fullKmGradeAdjustedPaceSec($split);
            if ($pace === null) {
                if ($segment !== []) {
                    $segments[] = $segment;
                    $segment = [];
                }
                $previousPace = null;

                continue;
            }
            if ($previousPace !== null && abs($pace - $previousPace) >= IntervalDetector::REP_PACE_GAP_SEC) {
                $segments[] = $segment;
                $segment = [];
            }
            $segment[] = $split;
            $previousPace = $pace;
        }
        if ($segment !== []) {
            $segments[] = $segment;
        }

        $steadyStats = null;
        $steadyMovingTime = 0.0;
        foreach ($segments as $candidate) {
            $candidateMovingTime = self::movingTimeSec($candidate);
            if ($candidateMovingTime < self::DRIFT_MIN_SEGMENT_MOVING_SEC
                || $candidateMovingTime <= $steadyMovingTime
                || count($candidate) < 2) {
                continue;
            }

            $half = (int) ceil(count($candidate) / 2);
            $first = $this->driftStats(array_slice($candidate, 0, $half));
            $second = $this->driftStats(array_slice($candidate, $half));
            if ($first !== null && $second !== null && $first['ratio'] > 0) {
                $steadyMovingTime = $candidateMovingTime;
                $steadyStats = ['first' => $first, 'second' => $second];
            }
        }

        $decoupling = null;
        $hrDrift = null;
        if ($steadyStats !== null) {
            $pct = ($steadyStats['second']['ratio'] / $steadyStats['first']['ratio'] - 1) * 100;
            if (abs($pct) <= self::DECOUPLING_IMPLAUSIBLE_PCT) {
                $decoupling = round($pct, 1);
                $hrDrift = round($steadyStats['second']['avg_hr'] - $steadyStats['first']['avg_hr'], 1);
            }
        }

        return [
            'drift_metric_version' => self::DRIFT_METRIC_VERSION,
            'steady_effort_decoupling_pct' => $decoupling,
            'steady_effort_hr_drift_bpm' => $hrDrift,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $splits
     */
    private static function movingTimeSec(array $splits): float
    {
        return array_sum(array_map(
            fn (array $split): float => max(0.0, (float) ($split['moving_time'] ?? 0)),
            $splits,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $splits
     * @return array{avg_hr: float, ratio: float}|null
     */
    private function driftStats(array $splits): ?array
    {
        $hrSeconds = 0.0;
        $movingSeconds = 0.0;
        $flatEquivalentDistance = 0.0;
        foreach ($splits as $split) {
            $splitMovingTime = (float) ($split['moving_time'] ?? 0);
            $bpm = $split['average_heartrate'] ?? null;
            if (! is_numeric($bpm)
                || (float) $bpm < self::HR_PLAUSIBLE_MIN_BPM
                || (float) $bpm > self::HR_PLAUSIBLE_MAX_BPM) {
                return null;
            }

            $gradeAdjustedPace = $this->fullKmGradeAdjustedPaceSec($split);
            if ($gradeAdjustedPace === null) {
                return null;
            }
            $splitFlatEquivalentDistance = $splitMovingTime / $gradeAdjustedPace * 1000;
            $hrSeconds += (float) $bpm * $splitMovingTime;
            $movingSeconds += $splitMovingTime;
            $flatEquivalentDistance += $splitFlatEquivalentDistance;
        }
        if ($movingSeconds <= 0 || $flatEquivalentDistance <= 0) {
            return null;
        }

        $avgHr = $hrSeconds / $movingSeconds;
        $flatPace = $movingSeconds / ($flatEquivalentDistance / 1000);

        return ['avg_hr' => $avgHr, 'ratio' => $avgHr * $flatPace];
    }

    /**
     * Pace adjusted for the split's average grade, or null when the split does
     * not have enough distance, time or elevation data for a comparable pace.
     *
     * @param  array<string, mixed>  $split
     */
    private function fullKmGradeAdjustedPaceSec(array $split): ?float
    {
        $distance = (float) ($split['distance'] ?? 0);
        $moving = (float) ($split['moving_time'] ?? 0);
        $elevationDifference = $split['elevation_difference'] ?? null;
        if ($distance < self::FULL_KM_MIN_DISTANCE_M || $moving <= 0 || ! is_numeric($elevationDifference)) {
            return null;
        }

        $grade = (float) $elevationDifference / $distance;
        if (abs($grade * 100) > self::TERRAIN_UNREADABLE_GRADE_PCT) {
            return null;
        }

        $flatEquivalentDistance = $distance * $this->gradeCostFactor($grade);

        return $flatEquivalentDistance > 0 ? $moving / ($flatEquivalentDistance / 1000) : null;
    }

    /**
     * Spread of the *per-km split* paces, in sec/km. Deliberately not the spread
     * of the instantaneous velocity stream: that samples every GPS wobble,
     * traffic light and cadence tick, so on real streams it lands an order of
     * magnitude above anything a runner would call "uneven" and never resolves
     * to a steady run. Split-level spread is the figure a runner can act on.
     *
     * Needs two full kilometres to describe anything, so shorter runs omit it.
     *
     * @param  array<int, array<string, mixed>>  $splits
     * @return array<string, float>
     */
    private function paceVariability(array $splits): array
    {
        $paces = [];
        foreach ($splits as $split) {
            $pace = $this->fullKmPaceSec($split);
            if ($pace !== null) {
                $paces[] = $pace;
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
     * Whether the run is long enough for half-vs-half drift metrics to describe
     * physiology rather than noise.
     *
     * Measured across the same samples {@see decoupling} will actually analyse
     * ({@see driftSampleCount}), not the full time stream: the streams can
     * arrive at different lengths, and a run whose velocity trace stops early
     * would otherwise be certified on 90 minutes of elapsed time while the ratio
     * was computed from the ten minutes that had data. Stopped time comes from
     * the summary so stop detection keeps a single definition.
     *
     * @param  list<float|int>  $time
     * @param  list<float|int>  $heartrate
     * @param  list<float|int>  $velocity
     * @param  list<float|int>  $grade
     * @param  array<string, mixed>  $summary  must already carry stoppedTime()'s output
     */
    private function isSustainedEffort(array $time, array $heartrate, array $velocity, array $grade, array $summary): bool
    {
        $n = self::driftSampleCount($time, $heartrate, $velocity, $grade);
        if ($n < 1) {
            return false;
        }

        $analysed = (float) $time[$n] - (float) $time[0];
        $stopped = (float) ($summary['stopped_time_sec'] ?? 0);

        return ($analysed - $stopped) >= self::DECOUPLING_MIN_MOVING_SEC;
    }

    /**
     * How many sample intervals the half-vs-half drift metrics can read: every
     * stream they consume has to cover the index, and each interval needs its
     * closing timestamp.
     *
     * The gradient is one of those streams. The pace inside the ratio is
     * grade-adjusted by contract ({@see decoupling}), so a run whose grade trace
     * is missing or short carries no reading over that stretch rather than one
     * computed as though the ground were flat. That is the same set
     * {@see gradeAdjustedPace} reads, and the mismatch used to let the tail of a
     * hilly run be scored flat.
     *
     * @param  list<float|int>  $time
     * @param  list<float|int>  $heartrate
     * @param  list<float|int>  $velocity
     * @param  list<float|int>  $grade
     */
    private static function driftSampleCount(array $time, array $heartrate, array $velocity, array $grade): int
    {
        return max(0, min(count($time) - 1, count($heartrate), count($velocity), count($grade)));
    }

    /**
     * @param  list<float>  $intervals
     * @param  list<float|int>  $velocity
     * @return array<string, int|float>
     */
    private function stoppedTime(array $intervals, array $velocity): array
    {
        $stopped = 0.0;
        $count = 0;
        $inStop = false;
        $n = min(count($velocity), count($intervals));
        for ($i = 0; $i < $n; $i++) {
            if ((float) $velocity[$i] < self::STOP_VELOCITY_MS) {
                $stopped += $intervals[$i];
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
     * Pace is **grade-adjusted** before the ratio is taken, via the same
     * Minetti cost factor {@see self::gradeAdjustedPace()} uses. Against raw
     * pace this metric could not tell fatigue from terrain: a climb in the
     * back half reads as decoupling, and a downhill finish flatters it. The
     * grade stream is already fetched for GAP, so this costs nothing extra.
     *
     * Both halves are **time-weighted**, and the split is the run's time
     * midpoint rather than the middle index. Each half's pace is its moving
     * seconds over its flat-equivalent distance, the same shape
     * {@see self::gradeAdjustedPace()} computes, so a slow sample weighs what
     * its seconds are worth. Averaging per-sample s/km instead let one crawling
     * sample near the stop threshold count for the same as a running one while
     * standing for a couple of metres, and an index split cut the halves
     * unevenly in time whenever the sample spacing changed mid-run.
     *
     * @param  list<float>  $seconds
     * @param  list<float>  $intervals
     * @param  list<float|int>  $heartrate
     * @param  list<float|int>  $velocity
     * @param  list<float>  $gradeCost  per-sample {@see self::gradeCostFactor()}
     * @return array<string, float>
     */
    private function decoupling(array $seconds, array $intervals, array $heartrate, array $velocity, array $gradeCost): array
    {
        $n = self::driftSampleCount($seconds, $heartrate, $velocity, $gradeCost);
        if ($n < 4) {
            return [];
        }
        $midpoint = ($seconds[0] + $seconds[$n]) / 2;

        $firstHrSeconds = 0.0;
        $firstFlatEquivDist = 0.0;
        $firstMovingSeconds = 0.0;
        $secondHrSeconds = 0.0;
        $secondFlatEquivDist = 0.0;
        $secondMovingSeconds = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $v = (float) $velocity[$i];
            $bpm = (float) $heartrate[$i];
            if ($v < self::STOP_VELOCITY_MS || $bpm < self::HR_PLAUSIBLE_MIN_BPM || $bpm > self::HR_PLAUSIBLE_MAX_BPM) {
                continue;
            }
            $at = $seconds[$i];
            $dt = $intervals[$i];
            if ($dt <= 0) {
                continue;
            }
            // Flat-equivalent distance: climbing costs more per metre, so
            // the metres covered are scaled by that cost to compare like
            // with like.
            $flatEquivDelta = $v * $dt * $gradeCost[$i];
            if ($at < $midpoint) {
                $firstHrSeconds += $bpm * $dt;
                $firstFlatEquivDist += $flatEquivDelta;
                $firstMovingSeconds += $dt;
            } else {
                $secondHrSeconds += $bpm * $dt;
                $secondFlatEquivDist += $flatEquivDelta;
                $secondMovingSeconds += $dt;
            }
        }

        $ratio = fn (float $hrSeconds, float $flatEquivDist, float $movingSeconds): ?float => $movingSeconds > 0 && $flatEquivDist > 0
            ? ($hrSeconds / $movingSeconds) / ($movingSeconds / ($flatEquivDist / 1000))
            : null;

        $first = $ratio($firstHrSeconds, $firstFlatEquivDist, $firstMovingSeconds);
        $second = $ratio($secondHrSeconds, $secondFlatEquivDist, $secondMovingSeconds);
        if ($first === null || $second === null || $first <= 0) {
            return [];
        }
        $pct = round(($second / $first - 1) * 100, 1);

        return abs($pct) > self::DECOUPLING_IMPLAUSIBLE_PCT ? [] : ['decoupling_pct' => $pct];
    }

    /**
     * Cadence stream is "rotations per minute, single foot". Double it for
     * the conventional steps-per-minute (SPM) used in running.
     *
     * @param  list<float>  $intervals
     * @param  list<float|int>  $cadence
     * @return array<string, mixed>
     */
    private function cadenceDistribution(array $intervals, array $cadence, int $optimalSpm): array
    {
        if ($intervals === [] || $cadence === []) {
            return [];
        }
        $buckets = ['<165' => 0.0, '165-175' => 0.0, '>175' => 0.0];
        $total = 0.0;
        $optimalLo = $optimalSpm;
        $optimalHi = $optimalSpm + 15;
        $optSec = 0.0;
        $n = min(count($cadence), count($intervals));
        for ($i = 0; $i < $n; $i++) {
            $dt = $intervals[$i];
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
        $last = array_last($splits);
        if (! is_array($last)) {
            return [];
        }
        $distance = (float) ($last['distance'] ?? 0);
        $moving = (float) ($last['moving_time'] ?? 0);
        if ($distance >= self::FULL_KM_MIN_DISTANCE_M || $distance < self::PARTIAL_SPLIT_MIN_DISTANCE_M || $moving <= 0) {
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
     * @param  list<float>  $intervals
     * @param  list<float|int>  $distance
     * @param  list<float|int>  $cadence
     * @return array<int, int>  km index (1-based) → average spm
     */
    private function perKmCadenceFromStream(array $intervals, array $distance, array $cadence): array
    {
        $n = min(count($cadence), count($distance), count($intervals));
        if ($n <= 0) {
            return [];
        }

        /** @var array<int, array{sum: float, dt: float}> $buckets */
        $buckets = [];
        for ($i = 0; $i < $n; $i++) {
            $dt = $intervals[$i];
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
     * Gated on the same sustained-effort floor as decoupling, and for the same
     * reason: over a short run the gap between the first and last kilometre's HR
     * is warm-up and terrain, not drift. Measured from the splits themselves so
     * the metric stays available whenever its own inputs are.
     *
     * @param  array<int, array<string, mixed>>  $splits
     * @return array<string, float>
     */
    private function hrDriftFromSplits(array $splits): array
    {
        $full = $this->fullKmSplits($splits);
        if (count($full) < 2) {
            return [];
        }
        $movingTime = array_sum(array_map(fn (array $s): float => (float) ($s['moving_time'] ?? 0), $full));
        if ($movingTime < self::DECOUPLING_MIN_MOVING_SEC) {
            return [];
        }
        $first = $full[0]['average_heartrate'] ?? null;
        $last = array_last($full)['average_heartrate'] ?? null;
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
        $full = $this->fullKmSplits($splits);
        if (count($full) < 2) {
            return [];
        }
        $first = $full[0]['average_cadence'] ?? null;
        $last = array_last($full)['average_cadence'] ?? null;
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
        $full = $this->fullKmSplits($splits);
        if (count($full) < 2) {
            return [];
        }
        $half = (int) ceil(count($full) / 2);
        $firstHalf = array_slice($full, 0, $half);
        $secondHalf = array_slice($full, $half);
        $firstAvg = array_sum(array_column($firstHalf, 'average_speed')) / count($firstHalf);
        $secondAvg = array_sum(array_column($secondHalf, 'average_speed')) / count($secondHalf);

        return ['negative_split' => $secondAvg > $firstAvg * self::NEGATIVE_SPLIT_MARGIN];
    }

}
