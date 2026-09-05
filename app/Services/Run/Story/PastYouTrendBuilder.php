<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Enums\TrendDirection;
use App\Enums\TrendVerdict;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\PaceConsistency;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Builds the home screen's Past You verdict: the runner's recent runs matched
 * against their own older comparable ones, aggregated into one honest call.
 *
 * The whole matching path reads summary fields only, so a run still queued for
 * lazy detail hydration counts on both sides of a comparison. The supporting
 * readings (fitness trend, pace consistency, relative effort) come from the
 * detail pipeline and stay null until it catches up.
 */
class PastYouTrendBuilder
{
    /**
     * The rolling window of "recent". 42 days is the CTL time constant this
     * codebase already treats as the horizon over which fitness meaningfully
     * moves ({@see TrainingLoad}), so the verdict and the fitness reading beside
     * it describe the same stretch of training.
     */
    public const int WINDOW_DAYS = 42;

    /** Below this the window has evidence but not a trend. */
    public const int MIN_COMPARISONS = 2;

    public const int MAX_COMPARISONS = 4;

    private const int HISTORY_LIMIT = 400;

    public function __construct(
        private readonly PastYouMatcher $matcher,
        private readonly TrainingLoad $trainingLoad,
    ) {
    }

    public function build(User $user, ?Carbon $asOf = null): PastYouTrend
    {
        $anchor = ($asOf ?? Carbon::today())->copy()->endOfDay();
        $windowStart = $anchor->copy()->subDays(self::WINDOW_DAYS)->startOfDay();

        $runs = $this->loadHistory($user->id, $anchor);
        $comparisons = $this->collectComparisons($runs, $windowStart);

        if (count($comparisons) < self::MIN_COMPARISONS) {
            return PastYouTrend::notEnoughHistory(self::WINDOW_DAYS, $comparisons);
        }

        $meanPaceDelta = $this->mean(array_map(
            static fn (PastYouComparison $c): float => $c->paceDeltaSec,
            $comparisons,
        ));
        $meanHrDelta = $this->mean(array_values(array_filter(array_map(
            static fn (PastYouComparison $c): ?float => $c->hrDeltaBpm,
            $comparisons,
        ), static fn (?float $delta): bool => $delta !== null)));

        [$consistencyNow, $consistencyThen] = $this->consistencyShift($comparisons[0]);

        return new PastYouTrend(
            verdict: $this->verdict($comparisons, $meanPaceDelta, $meanHrDelta),
            comparisons: $comparisons,
            windowDays: self::WINDOW_DAYS,
            meanPaceDeltaSec: $meanPaceDelta,
            meanHrDeltaBpm: $meanHrDelta,
            fitnessDeltaCtl: $this->fitnessDelta($user, $anchor),
            paceConsistencyNow: $consistencyNow,
            paceConsistencyThen: $consistencyThen,
        );
    }

    /**
     * A verdict needs the pairs to agree on a direction *and* the aggregate to
     * point the same way, so one lopsided comparison can't outvote the rest and
     * a bare majority of tiny gains can't be called improvement.
     *
     * @param  list<PastYouComparison>  $comparisons
     */
    private function verdict(array $comparisons, ?float $meanPaceDelta, ?float $meanHrDelta): TrendVerdict
    {
        $netVotes = 0;
        foreach ($comparisons as $comparison) {
            $direction = $comparison->direction();
            $netVotes += $direction->isBetter() ? 1 : ($direction->isWorse() ? -1 : 0);
        }

        $aggregate = $this->aggregateDirection($meanPaceDelta, $meanHrDelta);

        return match (true) {
            $netVotes > 0 && $aggregate->isBetter() => TrendVerdict::Improving,
            $netVotes < 0 && $aggregate->isWorse() => TrendVerdict::Slipped,
            default => TrendVerdict::Plateaued,
        };
    }

    /**
     * Pace decides unless the mean came back inside the noise band, in which
     * case heart rate does: holding pace at a lower heart rate is a gain, and
     * holding it at a higher one is a loss.
     */
    private function aggregateDirection(?float $meanPaceDelta, ?float $meanHrDelta): TrendDirection
    {
        if ($meanPaceDelta !== null && abs($meanPaceDelta) >= PastYouComparison::PACE_SIGNAL_SEC) {
            return $meanPaceDelta > 0 ? TrendDirection::Better : TrendDirection::Worse;
        }

        if ($meanHrDelta !== null && abs($meanHrDelta) >= PastYouComparison::HR_SIGNAL_BPM) {
            return $meanHrDelta < 0 ? TrendDirection::Better : TrendDirection::Worse;
        }

        return TrendDirection::Flat;
    }

    /**
     * @param  list<ComparableRun>  $runs  newest first
     * @return list<PastYouComparison>
     */
    private function collectComparisons(array $runs, Carbon $windowStart): array
    {
        $recent = array_values(array_filter(
            $runs,
            static fn (ComparableRun $run): bool => $run->startedAt->gte($windowStart),
        ));
        $candidates = array_values(array_filter(
            $runs,
            static fn (ComparableRun $run): bool => $run->startedAt->lt($windowStart),
        ));

        $comparisons = [];
        $usedPastIds = [];

        foreach ($recent as $run) {
            if (count($comparisons) >= self::MAX_COMPARISONS) {
                break;
            }

            $available = array_values(array_filter(
                $candidates,
                static fn (ComparableRun $c): bool => ! in_array($c->activityId, $usedPastIds, true),
            ));

            $comparison = $this->matcher->bestMatch($run, $available);
            if ($comparison === null) {
                continue;
            }

            $usedPastIds[] = $comparison->past->activityId;
            $comparisons[] = $comparison;
        }

        return $comparisons;
    }

    /**
     * @return list<ComparableRun>  newest first
     */
    private function loadHistory(int $userId, Carbon $anchor): array
    {
        /** @var Collection<int, ActivityDetail> $details */
        $details = ActivityDetail::query()
            ->select([
                'id', 'activity_id', 'start_date_local', 'distance', 'moving_time',
                'average_heartrate', 'total_elevation_gain',
            ])
            ->forUser($userId)
            ->whereNotNull('start_date_local')
            ->where('start_date_local', '<=', $anchor)
            ->where('start_date_local', '>=', $anchor->copy()
                ->subDays(self::WINDOW_DAYS + PastYouMatcher::MAX_GAP_DAYS)->startOfDay())
            ->where('distance', '>', 0)
            ->where('moving_time', '>', 0)
            ->with('activity:id,ingest_state')
            ->orderByDesc('start_date_local')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        $runs = [];
        foreach ($details as $detail) {
            $run = ComparableRun::fromDetail($detail, $detail->activity->ingest_state);
            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    private function fitnessDelta(User $user, Carbon $anchor): ?float
    {
        $trend = $this->trainingLoad->ctlTrend($user, self::WINDOW_DAYS, $anchor);
        if (count($trend) < 2) {
            return null;
        }

        return round($trend[count($trend) - 1]['ctl'] - $trend[0]['ctl'], 1);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function consistencyShift(PastYouComparison $comparison): array
    {
        /** @var Collection<int, ActivityDetail> $details */
        $details = ActivityDetail::query()
            ->select(['id', 'activity_id', 'stream_summary'])
            ->whereIn('activity_id', [$comparison->current->activityId, $comparison->past->activityId])
            ->get();

        $byActivity = $details->keyBy('activity_id');

        return [
            $this->consistencyLabel($byActivity->get($comparison->current->activityId)),
            $this->consistencyLabel($byActivity->get($comparison->past->activityId)),
        ];
    }

    private function consistencyLabel(?ActivityDetail $detail): ?string
    {
        if ($detail === null) {
            return null;
        }

        return PaceConsistency::label(StreamSummary::fromArray($detail->stream_summary)->paceVariabilitySec());
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 1);
    }
}
