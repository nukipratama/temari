<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Enums\PrCategory;
use App\Models\PersonalRecord;
use App\Models\User;

/**
 * Projects a realistic race time for a target distance from the athlete's own
 * personal records, using Riegel's formula: T2 = T1 * (D2/D1)^exponent.
 *
 * Riegel's own exponent (1.06) is a population average. This fits the
 * exponent to the athlete's *own* PRs instead of assuming it, via a log-log
 * linear regression across every usable (distance, time) pair: distance PRs
 * contribute directly, effort-window PRs ({@see PrCategory::efforts()}) store
 * a pace (sec/km), not elapsed time, so they are converted first.
 *
 * `personal_records` holds at most one row per category (11 categories, no
 * time-series), so a thin sample — 0 or 1 usable PR — is the common case, not
 * an edge case: those fall back to the default 1.06 exponent and widen the
 * predicted range rather than claim false precision from one data point.
 *
 * Deliberately does not reconcile with {@see VdotEstimator}, which solves a
 * different problem (training-pace prescription via min() across PRs, not
 * race-time projection).
 */
class RiegelProjector
{
    /** Riegel's population-average exponent, used when the athlete's own PRs are too thin to fit. */
    public const float DEFAULT_EXPONENT = 1.06;

    /**
     * Sanity bounds on a fitted exponent. A 2-point fit can be pulled to an
     * implausible slope by ordinary PR noise (e.g. a slightly-off effort PR);
     * clamping keeps the projection from extrapolating into a physiologically
     * meaningless prediction while still letting real signal move the number
     * away from the population-average default.
     */
    private const float MIN_EXPONENT = 0.90;

    private const float MAX_EXPONENT = 1.30;

    /**
     * Uncertainty half-width, as a fraction of the predicted time, by sample
     * size — widens as the sample thins out. A single-PR (or zero-PR default)
     * projection is genuinely uncertain and should read that way, not with
     * false precision.
     *
     * @var array<int, float>
     */
    private const array HALF_WIDTH_BY_SAMPLE = [
        0 => 0.15,
        1 => 0.15,
        2 => 0.10,
        3 => 0.07,
    ];

    /** Half-width floor for a rich (4+) sample — never claims zero uncertainty. */
    private const float HALF_WIDTH_RICH_SAMPLE = 0.04;

    /**
     * @return array{predicted_sec: float, low_sec: float, high_sec: float, exponent: float, sample_size: int, confidence: string}|null
     *                                                                                                                                     null when the athlete has no usable PR at all to anchor a projection from.
     */
    public function project(User $user, float $targetDistanceM): ?array
    {
        $pairs = $this->usablePairs($user);
        if ($pairs === []) {
            return null;
        }

        $exponent = $this->fitExponent($pairs);
        $predicted = $this->predict($pairs, $exponent, $targetDistanceM);

        $sampleSize = count($pairs);
        $halfWidth = self::HALF_WIDTH_BY_SAMPLE[$sampleSize] ?? self::HALF_WIDTH_RICH_SAMPLE;

        return [
            'predicted_sec' => round($predicted, 1),
            'low_sec' => round($predicted * (1 - $halfWidth), 1),
            'high_sec' => round($predicted * (1 + $halfWidth), 1),
            'exponent' => round($exponent, 4),
            'sample_size' => $sampleSize,
            'confidence' => match (true) {
                $sampleSize <= 1 => 'low',
                $sampleSize <= 3 => 'medium',
                default => 'high',
            },
        ];
    }

    /**
     * @param  list<array{distance_m: float, time_sec: float}>  $pairs
     */
    private function predict(array $pairs, float $exponent, float $targetDistanceM): float
    {
        if (count($pairs) === 1) {
            $only = $pairs[0];

            return $only['time_sec'] * ($targetDistanceM / $only['distance_m']) ** $exponent;
        }

        // T = a * D^exponent, fit in log-log space; solve for `a` (the
        // intercept) from the sample centroid so the fitted line passes
        // through it, then project directly rather than anchoring on any one
        // PR (which would make the choice of anchor an arbitrary decision).
        $xs = array_map(static fn (array $p): float => log($p['distance_m']), $pairs);
        $ys = array_map(static fn (array $p): float => log($p['time_sec']), $pairs);
        $xBar = array_sum($xs) / count($xs);
        $yBar = array_sum($ys) / count($ys);
        $logIntercept = $yBar - $exponent * $xBar;

        return exp($logIntercept + $exponent * log($targetDistanceM));
    }

    /**
     * Log-log linear regression slope across the sample. Falls back to the
     * default exponent (clamped bounds don't apply to it — it's already a
     * sane population value) when every pair shares the same log-distance,
     * which would otherwise divide by zero.
     *
     * @param  list<array{distance_m: float, time_sec: float}>  $pairs
     */
    private function fitExponent(array $pairs): float
    {
        if (count($pairs) < 2) {
            return self::DEFAULT_EXPONENT;
        }

        $xs = array_map(static fn (array $p): float => log($p['distance_m']), $pairs);
        $ys = array_map(static fn (array $p): float => log($p['time_sec']), $pairs);
        $xBar = array_sum($xs) / count($xs);
        $yBar = array_sum($ys) / count($ys);

        $sumXY = 0.0;
        $sumXX = 0.0;
        foreach ($xs as $i => $x) {
            $sumXY += ($x - $xBar) * ($ys[$i] - $yBar);
            $sumXX += ($x - $xBar) ** 2;
        }

        if ($sumXX < 1e-9) {
            return self::DEFAULT_EXPONENT;
        }

        $exponent = $sumXY / $sumXX;

        return min(self::MAX_EXPONENT, max(self::MIN_EXPONENT, $exponent));
    }

    /**
     * @return list<array{distance_m: float, time_sec: float}>
     */
    private function usablePairs(User $user): array
    {
        $prs = PersonalRecord::query()->where('user_id', $user->id)->get();

        $pairs = [];
        foreach ($prs as $pr) {
            $pair = $this->pairFor($pr);
            if ($pair !== null) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * @return array{distance_m: float, time_sec: float}|null
     */
    private function pairFor(PersonalRecord $pr): ?array
    {
        if ($pr->value_sec <= 0) {
            return null;
        }

        $distanceMeters = $pr->category->distanceMeters();
        if ($distanceMeters !== null) {
            return ['distance_m' => $distanceMeters, 'time_sec' => $pr->value_sec];
        }

        // Effort-window PRs store a pace (sec/km), not elapsed time, so the
        // window itself gives the time and the covered distance is derived
        // from pace * window.
        $windowSec = $this->effortWindowSeconds($pr->category);
        if ($windowSec === null) {
            return null;
        }

        return [
            'distance_m' => $windowSec / $pr->value_sec * 1000,
            'time_sec' => (float) $windowSec,
        ];
    }

    private function effortWindowSeconds(PrCategory $category): ?int
    {
        return match ($category->effortWindow()) {
            '5min' => 300,
            '10min' => 600,
            '20min' => 1200,
            '30min' => 1800,
            '60min' => 3600,
            default => null,
        };
    }
}
