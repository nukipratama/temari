<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use Carbon\CarbonInterface;
use App\Actions\Run\Plan\ResolveTrailingWeeksAction;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Gamification\StreakSettlementService;
use App\Services\Notifications\UsualRunTime;
use App\Services\Run\Story\PastYouTrendBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class WeeklyAggregator
{
    /** Runs that must carry a decoupling reading before the week gets an average of them. */
    private const int MIN_RUNS_FOR_AVG_DECOUPLING = 2;

    private const int LOCK_SECONDS = 120;

    private const int LOCK_WAIT_SECONDS = 20;

    /** @var array<int, true> */
    private static array $held = [];

    /** @var list<string> */
    private const array REBUILT_COLUMNS = [
        'distance_km',
        'runs',
        'elapsed_time_sec',
        'weekly_trimp',
        'atl_7d',
        'ctl_42d',
        'form',
        'form_status',
        'avg_decoupling',
        'avg_decoupling_v2',
        'monotony',
        'strain',
    ];

    /**
     * The only ActivityDetail columns the weekly roll-up reads: the week filter
     * and daily TRIMP map need the date, upsertWeek sums distance/elapsed_time,
     * and averageDecoupling reads `stream_summary`. Everything else on the table
     * (notably the `splits_metric` and `laps` blobs) would be a year of JSON
     * pulled per ingest for nothing.
     *
     * @var list<string>
     */
    private const array HISTORY_COLUMNS = [
        'activity_details.id',
        'activity_details.activity_id',
        'activity_details.start_date_local',
        'activity_details.distance',
        'activity_details.elapsed_time',
        'activity_details.trimp_edwards',
        'activity_details.stream_summary',
    ];

    public function __construct(
        private readonly TrainingLoad $trainingLoad,
    ) {
    }

    /**
     * Every per-user, per-day read derived from the history this rebuild is
     * about to change. All three are keyed by today's date and all only move
     * when an activity lands or leaves.
     */
    private function clearDerivedCaches(User $user): void
    {
        TrainingLoad::clearSummaryCache($user);
        PastYouTrendBuilder::clearCache($user);
        UsualRunTime::clearCache($user);
    }

    public static function lockKey(int $userId): string
    {
        return "weekly-aggregate:{$userId}";
    }

    /**
     * @template T
     *
     * @param  callable(): T  $rebuild
     * @return T
     */
    private function exclusively(User $user, callable $rebuild): mixed
    {
        if (isset(self::$held[$user->id]) && DB::transactionLevel() > 0) {
            return $rebuild();
        }

        $lock = Cache::lock(self::lockKey($user->id), self::LOCK_SECONDS);
        $lock->block(self::LOCK_WAIT_SECONDS);
        self::$held[$user->id] = true;

        $released = false;
        $release = function () use ($lock, $user, &$released): void {
            if ($released) {
                return;
            }
            $released = true;
            unset(self::$held[$user->id]);
            $lock->release();
        };

        try {
            $result = $rebuild();
        } catch (Throwable $e) {
            $release();

            throw $e;
        }

        DB::afterRollBack($release);
        DB::afterCommit($release);

        return $result;
    }

    public function rebuildForWeekOf(User $user, Carbon $when): ?WeeklySnapshot
    {
        return $this->exclusively($user, function () use ($user, $when): ?WeeklySnapshot {
            $this->clearDerivedCaches($user);
            $weekEnding = $when->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();

            // Load a converged lead-in window through this week so the CTL EWMA
            // settles as a continuous series; a short warm-up window would yield a
            // too-low, window-dependent CTL.
            $details = $this->loadHistoryThrough($user, $weekEnding, $this->leadInStart($weekEnding));
            if ($details->isEmpty()) {
                return null;
            }

            $this->writeWeeks($user, $weekEnding, $this->weekRows($user, $weekEnding, $weekEnding, $details));

            return $this->snapshotFor($user, $weekEnding);
        });
    }

    /**
     * Rebuild the weekly snapshot for $weekAnchor's week and every later week
     * through today, returning the anchor week's snapshot. CTL is cumulative, so
     * a backdated activity must propagate its load forward into every subsequent
     * week's snapshot.
     */
    public function rebuildForwardFrom(User $user, CarbonInterface $weekAnchor): ?WeeklySnapshot
    {
        return $this->exclusively($user, fn (): ?WeeklySnapshot => $this->rollForward($user, $weekAnchor));
    }

    /**
     * Records $from's week as the earliest one whose snapshots no longer match
     * the history, for {@see rollForwardDirty} to rebuild later in one pass.
     */
    public function markDirty(User $user, CarbonInterface $from): void
    {
        $this->exclusively($user, fn () => $this->markDirtyFrom($user, $from));
    }

    /**
     * Rolls forward from the earliest dirty week and clears the mark, or does
     * nothing when no week is dirty.
     */
    public function rollForwardDirty(User $user): void
    {
        $this->exclusively($user, fn () => $this->drainDirty($user));
    }

    /**
     * Marks $from's week dirty before rebuilding, so a worker killed mid-rebuild
     * leaves the mark for {@see rollForwardDirty} to finish the job.
     */
    public function rollForwardFrom(User $user, CarbonInterface $from): void
    {
        $this->exclusively($user, function () use ($user, $from): void {
            $this->markDirtyFrom($user, $from);
            $this->drainDirty($user);
        });
    }

    private function markDirtyFrom(User $user, CarbonInterface $from): void
    {
        $weekEnding = Carbon::instance($from)->endOfWeek(Carbon::SUNDAY)->toDateString();

        User::query()
            ->whereKey($user->id)
            ->where(fn (Builder $query) => $query
                ->whereNull('weekly_snapshots_dirty_from')
                ->orWhere('weekly_snapshots_dirty_from', '>', $weekEnding))
            ->update(['weekly_snapshots_dirty_from' => $weekEnding]);
    }

    private function drainDirty(User $user): void
    {
        $dirtyFrom = User::query()->whereKey($user->id)->first(['id', 'weekly_snapshots_dirty_from'])?->weekly_snapshots_dirty_from;
        if ($dirtyFrom === null) {
            return;
        }

        $this->rollForward($user, $dirtyFrom);
        User::query()->whereKey($user->id)->update(['weekly_snapshots_dirty_from' => null]);
    }

    private function rollForward(User $user, CarbonInterface $weekAnchor): ?WeeklySnapshot
    {
        $this->clearDerivedCaches($user);
        $anchorWeekEnding = Carbon::instance($weekAnchor)->endOfWeek(Carbon::SUNDAY)->startOfDay();
        $lastWeekEnding = Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay();

        // Load the lead-in window once (sized so even the anchor week has a
        // converged CTL) and roll every week's snapshot from this shared series,
        // so a backdated activity propagates forward in one query.
        $details = $this->loadHistoryThrough($user, $lastWeekEnding, $this->leadInStart($anchorWeekEnding));
        if ($details->isEmpty()) {
            return null;
        }

        $this->writeWeeks($user, $anchorWeekEnding, $this->weekRows($user, $anchorWeekEnding, $lastWeekEnding, $details));

        return $this->snapshotFor($user, $anchorWeekEnding);
    }

    /**
     * Start of the converged lead-in window for a week, so the CTL EWMA has
     * enough history before $weekEnding to settle (see {@see TrainingLoad}).
     */
    private function leadInStart(Carbon $weekEnding): Carbon
    {
        return $weekEnding->copy()->subDays(TrainingLoad::CONVERGED_LOOKBACK_DAYS)->startOfDay();
    }

    private function snapshotFor(User $user, Carbon $weekEnding): ?WeeklySnapshot
    {
        return WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('week_ending', $weekEnding->toDateString())
            ->first();
    }

    /**
     * @return Collection<int, ActivityDetail>
     */
    private function loadHistoryThrough(User $user, Carbon $weekEnding, Carbon $from): Collection
    {
        // Materialize once: upsertWeek enumerates this set several times per week
        // (filter + sums + decoupling), and a lazy query would re-run the whole
        // 365-day scan on each pass. A user-year of rows fits comfortably in memory.
        return Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->where('activity_details.start_date_local', '>=', $from)
            ->where('activity_details.start_date_local', '<=', $weekEnding->copy()->endOfDay())
            ->orderBy('activity_details.start_date_local')
            ->select(self::HISTORY_COLUMNS)
            ->get();
    }

    public function rebuildFor(User $user): int
    {
        return $this->exclusively($user, function () use ($user): int {
            $this->clearDerivedCaches($user);
            $details = Activity::analyzedJoinConstraint(
                ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
            )
                ->where('activities.user_id', $user->id)
                ->whereNotNull('activity_details.start_date_local')
                ->orderBy('activity_details.start_date_local')
                ->select(self::HISTORY_COLUMNS)
                ->get();

            if ($details->isEmpty()) {
                return 0;
            }

            // Non-empty (guarded above), so first() is present.
            $firstDetail = $details->first();

            /** @var Carbon $earliest */
            $earliest = $firstDetail->start_date_local;
            $firstWeekEnding = $earliest->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
            $today = Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay();

            $rows = $this->weekRows($user, $firstWeekEnding, $today, $details);
            $this->writeWeeks($user, $firstWeekEnding, $rows);

            return \count($rows);
        });
    }

    /**
     * A query upsert skips the model's saved hooks, so their side effects are replayed here.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeWeeks(User $user, Carbon $earliestWeekEnding, array $rows): void
    {
        WeeklySnapshot::query()->upsert($rows, ['user_id', 'week_ending'], self::REBUILT_COLUMNS);

        app(ResolveTrailingWeeksAction::class)->forget($user->id);
        app(StreakSettlementService::class)->markDirty($user->id, $earliestWeekEnding);
    }

    /**
     * One row per week from $firstWeekEnding through $lastWeekEnding, each
     * reading its own bucket of $details and its ATL/CTL from one EWMA roll
     * over the whole range, so the cost stays linear in the history.
     *
     * @param  Collection<int, ActivityDetail>  $details
     * @return list<array<string, mixed>>
     */
    private function weekRows(User $user, Carbon $firstWeekEnding, Carbon $lastWeekEnding, Collection $details): array
    {
        ['trimp' => $dailyTrimp, 'runDays' => $runDays] = $this->dailyHistory($details);
        $byWeek = $details->groupBy(
            fn (ActivityDetail $d): string => (string) $d->start_date_local?->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        );
        $today = Carbon::today()->startOfDay();
        $loadSeries = $dailyTrimp === []
            ? []
            : $this->trainingLoad->rollDailySeries($dailyTrimp, $lastWeekEnding->lessThan($today) ? $lastWeekEnding : $today);

        $rows = [];
        for ($weekEnding = $firstWeekEnding->copy(); $weekEnding->lte($lastWeekEnding); $weekEnding = $weekEnding->copy()->addWeek()) {
            $weekDetails = $byWeek->get($weekEnding->toDateString(), new Collection());
            $rows[] = $this->weekRow($user, $weekEnding, $weekDetails, $dailyTrimp, $runDays, $loadSeries, $today);
        }

        return $rows;
    }

    /**
     * @param  Enumerable<int, ActivityDetail>  $weekDetails
     * @param  array<string, float>  $dailyTrimp
     * @param  array<string, true>  $runDays
     * @param  array<string, array{0: float, 1: float}>  $loadSeries
     * @return array<string, mixed>
     */
    private function weekRow(User $user, Carbon $weekEnding, Enumerable $weekDetails, array $dailyTrimp, array $runDays, array $loadSeries, Carbon $today): array
    {
        $distanceKm = DistanceFormatter::km((float) $weekDetails->sum('distance'));
        $runs = $weekDetails->count();
        $elapsedTimeSec = (int) round((float) $weekDetails->sum('elapsed_time'));
        $avgDecoupling = $this->averageDecoupling($weekDetails);
        $avgDecouplingV2 = $this->averageSegmentDecoupling($weekDetails);

        // For the in-progress week, measure ATL/CTL as-of today rather than the
        // future Sunday, so days that have not happened yet are not zero-filled
        // (which would understate current fitness). Past weeks are unaffected.
        $loadAsOf = $weekEnding->lessThan($today) ? $weekEnding : $today;
        $summary = $this->trainingLoad->summaryFromDailyMap($dailyTrimp, $runDays, $weekEnding, $loadAsOf, loadSeries: $loadSeries);

        return [
            'user_id' => $user->id,
            'week_ending' => $weekEnding->toDateString(),
            'distance_km' => $distanceKm,
            'runs' => $runs,
            'elapsed_time_sec' => $elapsedTimeSec,
            'weekly_trimp' => $summary['weekly_trimp'] ?? null,
            'atl_7d' => $summary['atl_7d'] ?? null,
            'ctl_42d' => $summary['ctl_42d'] ?? null,
            'form' => $summary['form'] ?? null,
            'form_status' => $summary['form_status'] ?? null,
            'avg_decoupling' => $avgDecoupling,
            'avg_decoupling_v2' => $avgDecouplingV2,
            'monotony' => $summary['monotony'] ?? null,
            'strain' => $summary['strain'] ?? null,
        ];
    }

    /**
     * The same two maps {@see TrainingLoad::summaryFromDailyMap} needs, derived
     * from one already-loaded detail set: scored days, and every day a run
     * happened on whether or not it carried heart rate.
     *
     * @param  Enumerable<int, ActivityDetail>  $details
     * @return array{trimp: array<string, float>, runDays: array<string, true>}
     */
    private function dailyHistory(Enumerable $details): array
    {
        $trimp = [];
        $runDays = [];
        foreach ($details as $detail) {
            if ($detail->start_date_local === null) {
                continue;
            }
            $key = $detail->start_date_local->toDateString();
            $runDays[$key] = true;
            if ($detail->trimp_edwards !== null) {
                $trimp[$key] = ($trimp[$key] ?? 0.0) + (float) $detail->trimp_edwards;
            }
        }
        ksort($trimp);
        ksort($runDays);

        return ['trimp' => $trimp, 'runDays' => $runDays];
    }

    /**
     * The week's average cardiac drift, or null when too few runs carry a
     * reading. A mean over a single run is that run, and the plan and the
     * history chips read this column as a statement about the week: one long
     * run that drifted is a fact about Sunday, not about the seven days around
     * it. Null is already the "no signal" value here, per
     * {@see docs/decisions/unscored-load-is-null-not-zero.md}.
     *
     * @param  Enumerable<int, ActivityDetail>  $details
     */
    private function averageDecoupling(Enumerable $details): ?float
    {
        return self::averageDecouplingReading($details, fn (StreamSummary $summary): ?float => $summary->decouplingPct());
    }

    /**
     * The version 2 weekly average includes only runs with a measured steady-
     * effort segment and requires the same two-run minimum as the legacy field.
     *
     * @param  Enumerable<int, ActivityDetail>  $details
     */
    private function averageSegmentDecoupling(Enumerable $details): ?float
    {
        return self::averageDecouplingReading($details, fn (StreamSummary $summary): ?float => $summary->steadyEffortDecouplingPct());
    }

    /**
     * @param  Enumerable<int, ActivityDetail>  $details
     * @param  callable(StreamSummary): ?float  $reading
     */
    private static function averageDecouplingReading(Enumerable $details, callable $reading): ?float
    {
        $values = $details
            ->map(fn (ActivityDetail $detail): ?float => $reading(StreamSummary::fromArray($detail->stream_summary)))
            ->filter(fn (?float $value): bool => $value !== null);

        if ($values->count() < self::MIN_RUNS_FOR_AVG_DECOUPLING) {
            return null;
        }

        return round((float) $values->avg(), 2);
    }
}
