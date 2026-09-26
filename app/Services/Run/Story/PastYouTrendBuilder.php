<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Enums\Effort;
use App\Enums\TrendDirection;
use App\Enums\TrendVerdict;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\PaceConsistency;
use App\Services\Run\Metrics\RunEffort;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Plan\PlannedSessionTypes;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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

    /** Fewer pairs can be shown as an early read, but cannot support a trend. */
    public const int MIN_VERDICT_COMPARISONS = 3;

    public const int MAX_COMPARISONS = 4;

    /** Widest mean heart-rate shift the headline may still call "the same heart rate". */
    public const float SAME_HR_BPM = 2.0;

    /**
     * The date range is the real bound: {@see self::WINDOW_DAYS} back for the
     * recent side plus {@see PastYouMatcher::MAX_GAP_DAYS} for the candidates
     * each of them may pair with. This row count is only a backstop against
     * pathological data such as a duplicated import, sized above the 814 runs a
     * twice-a-day runner could log in that range; it drops the *oldest* rows,
     * which are eligible candidates.
     */
    private const int HISTORY_BACKSTOP_ROWS = 2_000;

    public function __construct(
        private readonly PastYouMatcher $matcher,
        private readonly TrainingLoad $trainingLoad,
    ) {
    }

    /**
     * The home screen's payload, memoized for the athlete's day. The verdict
     * only moves when a run lands, so {@see clearCache} at ingest is what makes
     * this fresh; the date in the key is what makes it roll over at midnight.
     *
     * @return array<string, mixed>
     */
    public function payload(User $user, ?Carbon $asOf = null): array
    {
        $day = ($asOf ?? Carbon::today())->toDateString();

        /** @var array<string, mixed> */
        return Cache::remember(
            self::cacheKey($user->id, $day),
            Carbon::tomorrow(),
            function () use ($user, $asOf): array {
                $trend = $this->build($user, $asOf);

                return $trend->toArray($this->effortsFor($user->id, $trend->comparisons));
            },
        );
    }

    public static function cacheKey(int $userId, string $day): string
    {
        return "past-you-trend:{$userId}:{$day}";
    }

    /** Called wherever an activity enters or leaves a user's history. */
    public static function clearCache(User $user): void
    {
        self::clearCacheForUserId($user->id);
    }

    public static function clearCacheForUserId(int $userId): void
    {
        Cache::forget(self::cacheKey($userId, Carbon::today()->toDateString()));
    }

    /**
     * Effort per activity on both sides of the shown comparisons — at most
     * {@see self::MAX_COMPARISONS} pairs, so this is a targeted lookup rather
     * than a scan of the whole comparison window.
     *
     * @param  list<PastYouComparison>  $comparisons
     * @return array<int, Effort>
     */
    private function effortsFor(int $userId, array $comparisons): array
    {
        if ($comparisons === []) {
            return [];
        }

        $activityIds = [];
        foreach ($comparisons as $comparison) {
            $activityIds[] = $comparison->current->activityId;
            $activityIds[] = $comparison->past->activityId;
        }

        $details = ActivityDetail::query()
            ->select(['id', 'activity_id', 'start_date_local', 'elapsed_time', 'workout_type', 'stream_summary'])
            ->whereIn('activity_id', array_unique($activityIds))
            ->get();

        return RunEffort::forDetails($userId, $details);
    }

    public function build(User $user, ?Carbon $asOf = null): PastYouTrend
    {
        $anchor = ($asOf ?? Carbon::today())->copy()->endOfDay();
        $windowStart = $anchor->copy()->subDays(self::WINDOW_DAYS)->startOfDay();

        $runs = $this->loadHistory($user->id, $anchor);
        $comparisons = $this->collectComparisons($runs, $windowStart);

        if (count($comparisons) < self::MIN_VERDICT_COMPARISONS) {
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
            verdict: $this->verdict($comparisons),
            comparisons: $comparisons,
            windowDays: self::WINDOW_DAYS,
            meanPaceDeltaSec: $meanPaceDelta,
            meanHrDeltaBpm: $meanHrDelta,
            fitnessDeltaCtl: $this->fitnessDelta($user, $anchor),
            paceConsistencyNow: $consistencyNow,
            paceConsistencyThen: $consistencyThen,
            verdictMetric: $this->verdictMetric($comparisons),
            paceRelation: PastYouComparison::paceRelation($this->meanOf(array_map(
                static fn (PastYouComparison $c): float => $c->paceChangePct(),
                $comparisons,
            ))),
            hrRelation: self::hrRelation($meanHrDelta),
        );
    }

    /** @return 'higher'|'lower'|'same'|null */
    private static function hrRelation(?float $meanHrDelta): ?string
    {
        return match (true) {
            $meanHrDelta === null => null,
            $meanHrDelta > self::SAME_HR_BPM => 'higher',
            $meanHrDelta < -self::SAME_HR_BPM => 'lower',
            default => 'same',
        };
    }

    /**
     * A verdict needs the pairs to agree on a direction *and* the aggregate to
     * point the same way, so one lopsided comparison can't outvote the rest and
     * a bare majority of tiny gains can't be called improvement.
     *
     * @param  list<PastYouComparison>  $comparisons
     */
    private function verdict(array $comparisons): TrendVerdict
    {
        $betterVotes = 0;
        $worseVotes = 0;
        foreach ($comparisons as $comparison) {
            $direction = $comparison->direction();
            $betterVotes += $direction->isBetter() ? 1 : 0;
            $worseVotes += $direction->isWorse() ? 1 : 0;
        }

        if ($betterVotes > 0 && $worseVotes > 0) {
            return TrendVerdict::Mixed;
        }

        $aggregate = $this->aggregateDirection($comparisons);
        $minimumVotes = (int) ceil(count($comparisons) * 2 / 3);

        return match (true) {
            $betterVotes >= $minimumVotes && $aggregate->isBetter() => TrendVerdict::Improving,
            $worseVotes >= $minimumVotes && $aggregate->isWorse() => TrendVerdict::Slipped,
            default => TrendVerdict::Plateaued,
        };
    }

    /**
     * Each pair's change is read in multiples of its own metric's threshold, so
     * a window mixing efficiency pairs and pace-only pairs averages on one scale.
     *
     * @param  list<PastYouComparison>  $comparisons
     */
    private function aggregateDirection(array $comparisons): TrendDirection
    {
        $meanUnits = $this->meanOf(array_map(
            static fn (PastYouComparison $c): float => $c->signalUnits(),
            $comparisons,
        ));

        return match (true) {
            $meanUnits >= 1.0 => TrendDirection::Better,
            $meanUnits <= -1.0 => TrendDirection::Worse,
            default => TrendDirection::Flat,
        };
    }

    /** @param  list<PastYouComparison>  $comparisons */
    private function verdictMetric(array $comparisons): string
    {
        $metrics = array_values(array_unique(array_map(
            static fn (PastYouComparison $c): string => $c->metric()->value,
            $comparisons,
        )));

        return count($metrics) === 1 ? $metrics[0] : 'mixed';
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
     * Rows come back as plain records rather than models: nothing downstream of
     * {@see ComparableRun} touches Eloquent, and hydrating a year of history
     * into models cost more than the matching it feeds. The join replaces an
     * eager load of `activities` for the one column the runs need, so it also
     * has to re-apply {@see \App\Models\Scopes\AnalyzedScope} via
     * {@see Activity::analyzedJoinConstraint()} — a plain join bypasses
     * Eloquent's global scopes.
     *
     * @return list<ComparableRun>  newest first
     */
    private function loadHistory(int $userId, Carbon $anchor): array
    {
        $historyStart = $anchor->copy()->subDays(self::WINDOW_DAYS + PastYouMatcher::MAX_GAP_DAYS)->startOfDay();
        $plannedTypes = PlannedSessionTypes::byDate($userId, $historyStart, $anchor);

        $rows = Activity::analyzedJoinConstraint(
            ActivityDetail::query()
                ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
                ->select([
                    'activity_details.activity_id',
                    'activity_details.start_date_local',
                    'activity_details.distance',
                    'activity_details.elapsed_time',
                    'activity_details.average_heartrate',
                    'activity_details.total_elevation_gain',
                    'activity_details.weather_temp_c',
                    'activities.ingest_state',
                ]),
        )
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local')
            ->where('activity_details.start_date_local', '<=', $anchor)
            ->where('activity_details.start_date_local', '>=', $historyStart)
            ->where('activity_details.distance', '>', 0)
            ->where('activity_details.elapsed_time', '>', 0)
            ->orderByDesc('activity_details.start_date_local')
            ->limit(self::HISTORY_BACKSTOP_ROWS)
            ->toBase()
            ->get();

        $runs = [];
        foreach ($rows as $row) {
            $values = (array) $row;
            $runDate = Carbon::parse((string) $values['start_date_local'])->toDateString();
            $values['planned_session_type'] = ($plannedTypes[$runDate] ?? null)?->value;
            $run = ComparableRun::fromRow($values);
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
     * Kept at 2 decimal places: this feeds threshold comparisons (±1.0 signal
     * units, the 2%/3% pace and efficiency bands), and {@see self::mean()}'s
     * 1-decimal rounding is coarse enough to round a sub-threshold value like
     * 0.97 up to the 1.0 line.
     *
     * @param  list<float>  $values  never empty at either call site
     */
    private function meanOf(array $values): float
    {
        return $this->mean($values, 2) ?? 0.0;
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values, int $precision = 1): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), $precision);
    }
}
