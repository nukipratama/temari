<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Enums\ComparisonMetric;
use App\Enums\Effort;
use App\Enums\TrendDirection;

/**
 * One matched pair of the runner's own runs and the deltas between them.
 * `paceDeltaSec` is positive when the recent run is faster; `hrDeltaBpm` is
 * negative when the recent run's average heart rate is lower.
 */
final readonly class PastYouComparison
{
    /** A warm-up dominates a shorter run's average heart rate, so efficiency is not read below this. */
    public const int EF_MIN_ELAPSED_SEC = 1_200;

    public function __construct(
        public ComparableRun $current,
        public ComparableRun $past,
        public float $similarity,
        public float $paceDeltaSec,
        public ?float $hrDeltaBpm,
        public int $daysApart,
    ) {
    }

    public static function between(ComparableRun $current, ComparableRun $past, float $similarity): self
    {
        $hrDelta = $current->averageHeartrate === null || $past->averageHeartrate === null
            ? null
            : round($current->averageHeartrate - $past->averageHeartrate, 1);

        return new self(
            current: $current,
            past: $past,
            similarity: round($similarity, 3),
            paceDeltaSec: round($past->paceSecPerKm - $current->paceSecPerKm, 1),
            hrDeltaBpm: $hrDelta,
            daysApart: $past->daysBefore($current),
        );
    }

    public function metric(): ComparisonMetric
    {
        return self::metricFor(
            $this->current->elapsedTimeSec,
            $this->past->elapsedTimeSec,
            $this->current->averageHeartrate,
            $this->past->averageHeartrate,
        );
    }

    /** Positive when the recent run is better on the metric that decided the pair. */
    public function changePct(): float
    {
        return self::changePctFor(
            $this->metric(),
            $this->current->paceSecPerKm,
            $this->past->paceSecPerKm,
            $this->current->averageHeartrate,
            $this->past->averageHeartrate,
        );
    }

    public function paceChangePct(): float
    {
        return self::paceChangePctFor($this->current->paceSecPerKm, $this->past->paceSecPerKm);
    }

    /** The change expressed in multiples of its own metric's signal threshold. */
    public function signalUnits(): float
    {
        return $this->changePct() / $this->metric()->signalPct();
    }

    public function direction(): TrendDirection
    {
        return self::directionFor($this->metric(), $this->changePct());
    }

    public static function metricFor(int $currentElapsedSec, int $pastElapsedSec, ?float $currentHr, ?float $pastHr): ComparisonMetric
    {
        $efReadable = $currentHr !== null && $currentHr > 0.0
            && $pastHr !== null && $pastHr > 0.0
            && $currentElapsedSec >= self::EF_MIN_ELAPSED_SEC
            && $pastElapsedSec >= self::EF_MIN_ELAPSED_SEC;

        return $efReadable ? ComparisonMetric::Ef : ComparisonMetric::Pace;
    }

    public static function changePctFor(
        ComparisonMetric $metric,
        float $currentPaceSecPerKm,
        float $pastPaceSecPerKm,
        ?float $currentHr,
        ?float $pastHr,
    ): float {
        if ($metric === ComparisonMetric::Pace || $currentHr === null || $pastHr === null) {
            return self::paceChangePctFor($currentPaceSecPerKm, $pastPaceSecPerKm);
        }

        $efRatio = ($pastPaceSecPerKm / $currentPaceSecPerKm) * ($pastHr / $currentHr);

        return round(($efRatio - 1.0) * 100, 2);
    }

    /** Positive when the recent run is faster, relative to the past run's pace. */
    public static function paceChangePctFor(float $currentPaceSecPerKm, float $pastPaceSecPerKm): float
    {
        return round(($pastPaceSecPerKm - $currentPaceSecPerKm) / $pastPaceSecPerKm * 100, 2);
    }

    public static function directionFor(ComparisonMetric $metric, float $changePct): TrendDirection
    {
        return match (true) {
            $changePct >= $metric->signalPct() => TrendDirection::Better,
            $changePct <= -$metric->signalPct() => TrendDirection::Worse,
            default => TrendDirection::Flat,
        };
    }

    /** @return 'faster'|'slower'|'same' */
    public static function paceRelation(float $paceChangePct): string
    {
        return match (self::directionFor(ComparisonMetric::Pace, $paceChangePct)) {
            TrendDirection::Better => 'faster',
            TrendDirection::Worse => 'slower',
            TrendDirection::Flat => 'same',
        };
    }

    /**
     * @param  array<int, Effort>  $efforts  Keyed by activity id, from {@see \App\Services\Run\Metrics\RunEffort::forDetails}.
     * @return array{direction: string, metric: string, pace_relation: string, days_apart: int, similarity: float, pace_delta_sec: float, hr_delta_bpm: float|null, current: array<string, mixed>, past: array<string, mixed>}
     */
    public function toArray(array $efforts = []): array
    {
        return [
            'direction' => $this->direction()->value,
            'metric' => $this->metric()->value,
            'pace_relation' => self::paceRelation($this->paceChangePct()),
            'days_apart' => $this->daysApart,
            'similarity' => $this->similarity,
            'pace_delta_sec' => $this->paceDeltaSec,
            'hr_delta_bpm' => $this->hrDeltaBpm,
            'current' => $this->current->toArray($efforts[$this->current->activityId] ?? null),
            'past' => $this->past->toArray($efforts[$this->past->activityId] ?? null),
        ];
    }
}
