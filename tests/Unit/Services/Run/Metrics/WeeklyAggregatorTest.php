<?php

declare(strict_types=1);

use App\Actions\Run\Plan\ResolveTrailingWeeksAction;
use App\Enums\IngestState;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\Agent\Tools\WeekTotalsTool;
use App\Services\Notifications\UsualRunTime;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Run\Story\PastYouTrendBuilder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $this->aggregator = new WeeklyAggregator(app(TrainingLoad::class));
});
afterEach(fn () => Carbon::setTestNow());

it('returns 0 and creates no rows when the user has no analyzed runs', function (): void {
    $user = User::factory()->create();

    expect($this->aggregator->rebuildFor($user))->toBe(0)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('ignores a not-yet-analyzed activity entirely, including as the sole run', function (): void {
    $user = User::factory()->create();
    $stub = Activity::factory()->for($user)->stub()->create();
    ActivityDetail::factory()->for($stub)->create([
        'distance' => 8000,
        'start_date_local' => Carbon::today(),
    ]);

    expect($this->aggregator->rebuildFor($user))->toBe(0)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('upserts one snapshot per ISO week from first run through today', function (): void {
    $user = User::factory()->create();
    foreach ([21, 14, 7, 0] as $daysAgo) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => 8000,
            'moving_time' => 2400,
            'elapsed_time' => 2400,
            'trimp_edwards' => 60.0,
            'start_date_local' => Carbon::today()->subDays($daysAgo),
            'stream_summary' => ['decoupling_pct' => 3.0],
        ]);
    }

    $written = $this->aggregator->rebuildFor($user);

    // Frozen at 2026-05-11 (Mon); 21 days back spans 4 ISO weeks ending Sunday.
    expect($written)->toBe(4)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe(4);
});

it('aggregates distance, runs, and avg decoupling per week', function (): void {
    $user = User::factory()->create();
    $weekEnding = Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay();

    foreach ([['distance' => 6000, 'dec' => 2.0, 'dec_v2' => 6.0], ['distance' => 10000, 'dec' => 5.0, 'dec_v2' => 10.0]] as $cfg) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => $cfg['distance'],
            'moving_time' => 1800,
            'elapsed_time' => 1800,
            'trimp_edwards' => 50.0,
            'start_date_local' => $weekEnding->copy()->subDays(2),
            'stream_summary' => [
                'decoupling_pct' => $cfg['dec'],
                'drift_metric_version' => 2,
                'steady_effort_decoupling_pct' => $cfg['dec_v2'],
            ],
        ]);
    }

    $this->aggregator->rebuildFor($user);
    $snapshot = WeeklySnapshot::query()
        ->where('user_id', $user->id)
        ->where('week_ending', $weekEnding->toDateString())
        ->firstOrFail();

    expect($snapshot->distance_km)->toBe(16.0)
        ->and($snapshot->runs)->toBe(2)
        ->and($snapshot->avg_decoupling)->toBe(3.5)
        ->and($snapshot->avg_decoupling_v2)->toBe(8.0);
});

it('writes null avg_decoupling when no runs in the week have decoupling_pct', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'moving_time' => 1500,
        'elapsed_time' => 1500,
        'trimp_edwards' => 40.0,
        'start_date_local' => Carbon::today(),
        'stream_summary' => ['time_in_zone_min' => ['Z2' => 25]],
    ]);

    $this->aggregator->rebuildFor($user);

    $snapshot = WeeklySnapshot::query()->where('user_id', $user->id)->latest('week_ending')->firstOrFail();
    expect($snapshot->avg_decoupling)->toBeNull();
});

it('writes no weekly decoupling average when a single run is all there is to average', function (): void {
    // One long run that drifted is a fact about that Sunday. Copied into a
    // column the plan and the history chips read as a statement about the week,
    // it becomes a verdict on seven days nobody measured.
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 21000,
        'moving_time' => 7200,
        'elapsed_time' => 7200,
        'trimp_edwards' => 180.0,
        'start_date_local' => Carbon::today(),
        'stream_summary' => ['decoupling_pct' => 12.4],
    ]);

    $this->aggregator->rebuildFor($user);

    $snapshot = WeeklySnapshot::query()->where('user_id', $user->id)->latest('week_ending')->firstOrFail();
    expect($snapshot->runs)->toBe(1)
        ->and($snapshot->avg_decoupling)->toBeNull()
        ->and($snapshot->avg_decoupling_v2)->toBeNull();
});

it('does not mix versionless history into the version 2 weekly average', function (): void {
    $user = User::factory()->create();
    foreach ([
        ['decoupling_pct' => 18.0],
        ['decoupling_pct' => 9.0, 'drift_metric_version' => 2, 'steady_effort_decoupling_pct' => 4.0],
    ] as $streamSummary) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => 5000,
            'moving_time' => 1800,
            'elapsed_time' => 1800,
            'trimp_edwards' => 50.0,
            'start_date_local' => Carbon::today(),
            'stream_summary' => $streamSummary,
        ]);
    }

    $this->aggregator->rebuildFor($user);

    $snapshot = WeeklySnapshot::query()->where('user_id', $user->id)->latest('week_ending')->firstOrFail();
    expect($snapshot->avg_decoupling)->toBe(13.5)
        ->and($snapshot->avg_decoupling_v2)->toBeNull();
});

it('leaves load unknown, not zero, when no run scored a TRIMP', function (): void {
    // A brand-new connection's history is entirely summary-only, so nothing
    // carries trimp_edwards: volume is exact, load is unscored. Writing 0.0
    // would tell a stranger they did nothing.
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'moving_time' => 1500,
        'elapsed_time' => 1500,
        'trimp_edwards' => null,
        'start_date_local' => Carbon::today(),
    ]);

    $this->aggregator->rebuildFor($user);

    $snapshot = WeeklySnapshot::query()->where('user_id', $user->id)->latest('week_ending')->firstOrFail();
    expect($snapshot->runs)->toBe(1)
        ->and($snapshot->distance_km)->toBe(5.0)
        ->and($snapshot->weekly_trimp)->toBeNull()
        ->and($snapshot->atl_7d)->toBeNull()
        ->and($snapshot->ctl_42d)->toBeNull()
        ->and($snapshot->form)->toBeNull()
        ->and($snapshot->form_status)->toBeNull()
        ->and($snapshot->monotony)->toBeNull()
        ->and($snapshot->strain)->toBeNull();
});

it('keeps pre-HR load unknown after later scored runs arrive', function (string $rebuild): void {
    $user = User::factory()->create();
    $preHrDay = Carbon::today()->subWeeks(3);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8000,
        'trimp_edwards' => null,
        'start_date_local' => $preHrDay,
    ]);
    $before = $this->aggregator->rebuildForwardFrom($user, $preHrDay);
    $loadFields = ['atl_7d', 'ctl_42d', 'form', 'form_status'];
    expect($before->only($loadFields))->toBe(array_fill_keys($loadFields, null));

    $scored = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($scored)->create([
        'trimp_edwards' => 120.0,
        'start_date_local' => Carbon::today()->subWeek(),
    ]);

    match ($rebuild) {
        'full' => $this->aggregator->rebuildFor($user),
        'forward' => $this->aggregator->rebuildForwardFrom($user, $preHrDay),
        'dirty' => $this->aggregator->rollForwardFrom($user, $preHrDay),
    };

    $snapshot = $before->fresh();
    expect($snapshot->only($loadFields))->toBe(array_fill_keys($loadFields, null));
    $recapTotals = new WeekTotalsTool($snapshot)->handle([]);
    expect($recapTotals['load_balance'])->toBeNull();
})->with(['full', 'forward', 'dirty']);

it('persists a scored, a rest and an unscored week as three different facts', function (): void {
    $user = User::factory()->create();
    $thisWeek = Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay();
    $scoredWeek = $thisWeek->copy()->subWeeks(4);
    $restWeek = $thisWeek->copy()->subWeeks(2);
    $unscoredWeek = $thisWeek->copy()->subWeeks(1);

    $seed = function (Carbon $day, ?float $trimp) use ($user): void {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => 8000,
            'moving_time' => 2400,
            'elapsed_time' => 2400,
            'trimp_edwards' => $trimp,
            'start_date_local' => $day,
        ]);
    };
    $seed($scoredWeek->copy()->subDays(5), 120.0);
    $seed($scoredWeek->copy()->subDays(2), 90.0);
    $seed($unscoredWeek->copy()->subDays(4), null);
    $seed($unscoredWeek->copy()->subDays(1), null);

    $this->aggregator->rebuildFor($user);

    $rowFor = fn (Carbon $weekEnding): WeeklySnapshot => WeeklySnapshot::query()
        ->where('user_id', $user->id)
        ->where('week_ending', $weekEnding->toDateString())
        ->firstOrFail();

    $scored = $rowFor($scoredWeek);
    expect($scored->runs)->toBe(2)
        ->and($scored->weekly_trimp)->toBeGreaterThan(0.0)
        ->and($scored->monotony)->toBeGreaterThan(0.0)
        ->and($scored->strain)->toBeGreaterThan(0.0);

    $rest = $rowFor($restWeek);
    expect($rest->runs)->toBe(0)
        ->and($rest->weekly_trimp)->toBe(0.0)
        ->and($rest->monotony)->toBe(0.0)
        ->and($rest->strain)->toBe(0.0);

    // Ran 16 km, but nothing carried HR: volume known, load unknowable.
    $unscored = $rowFor($unscoredWeek);
    expect($unscored->runs)->toBe(2)
        ->and($unscored->distance_km)->toBe(16.0)
        ->and($unscored->weekly_trimp)->toBeNull()
        ->and($unscored->monotony)->toBeNull()
        ->and($unscored->strain)->toBeNull()
        ->and($unscored->ctl_42d)->toBeFloat();
});

it('is idempotent — re-running upserts the same week without duplicating', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 7000,
        'moving_time' => 2000,
        'elapsed_time' => 2000,
        'trimp_edwards' => 55.0,
        'start_date_local' => Carbon::today()->subDays(3),
    ]);

    $this->aggregator->rebuildFor($user);
    $first = WeeklySnapshot::query()->where('user_id', $user->id)->count();

    $this->aggregator->rebuildFor($user);
    $second = WeeklySnapshot::query()->where('user_id', $user->id)->count();

    expect($second)->toBe($first);
});

it('rebuildForwardFrom returns null when user has no runs', function (): void {
    $user = User::factory()->create();

    $snap = $this->aggregator->rebuildForwardFrom($user, Carbon::today());

    expect($snap)->toBeNull();
});

it('rebuildForwardFrom returns null when the only run that week is not yet analyzed', function (): void {
    $user = User::factory()->create();
    $stub = Activity::factory()->for($user)->stub()->create();
    ActivityDetail::factory()->for($stub)->create([
        'distance' => 8000,
        'start_date_local' => Carbon::today(),
    ]);

    $snap = $this->aggregator->rebuildForwardFrom($user, Carbon::today());

    expect($snap)->toBeNull();
});

it('rebuildForwardFrom computes the converged CTL, not the too-low windowed value', function (): void {
    // 200 days of steady 80 TRIMP/day ending on a COMPLETED past week, so CTL is
    // measured as-of the last active day (no in-progress-week zero-decay). A
    // continuous CTL converges near 80; the old 49-day warm-up window cold-started
    // it near 55.
    $user = User::factory()->create();
    $weekEnding = Carbon::today()->subWeek()->endOfWeek(Carbon::SUNDAY)->startOfDay();
    for ($i = 0; $i < 200; $i++) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => 8000,
            'moving_time' => 2400,
            'elapsed_time' => 2400,
            'trimp_edwards' => 80.0,
            'start_date_local' => $weekEnding->copy()->subDays(199 - $i),
        ]);
    }

    $snap = $this->aggregator->rebuildForwardFrom($user, $weekEnding);

    expect($snap)->not->toBeNull()
        ->and((float) $snap->ctl_42d)->toBeGreaterThan(75.0);
});

it('rebuildForwardFrom rebuilds every week from the anchor through today', function (): void {
    $user = User::factory()->create();
    foreach ([21, 14, 7, 0] as $daysAgo) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => 8000,
            'moving_time' => 2400,
            'elapsed_time' => 2400,
            'trimp_edwards' => 60.0,
            'start_date_local' => Carbon::today()->subDays($daysAgo),
        ]);
    }

    $this->aggregator->rebuildForwardFrom($user, Carbon::today()->subDays(21));

    // Frozen Mon 2026-05-11; 21 days back spans 4 ISO weeks ending Sunday.
    expect(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe(4);
});

it('rebuildForwardFrom propagates a backdated activity forward into later weeks CTL', function (): void {
    // Steady baseline across several weeks, fully populated first.
    $user = User::factory()->create();
    for ($i = 0; $i < 35; $i++) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => 6000,
            'moving_time' => 1800,
            'elapsed_time' => 1800,
            'trimp_edwards' => 40.0,
            'start_date_local' => Carbon::today()->subDays(34 - $i),
        ]);
    }
    $this->aggregator->rebuildFor($user);

    $latestWeekEnding = Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString();
    $ctlBefore = (float) WeeklySnapshot::query()
        ->where('user_id', $user->id)
        ->where('week_ending', $latestWeekEnding)
        ->value('ctl_42d');

    // Backdate a big TRIMP day into the oldest week (webhook old-upload case).
    $backdate = Carbon::today()->subDays(33);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 20000,
        'moving_time' => 7200,
        'elapsed_time' => 7200,
        'trimp_edwards' => 300.0,
        'start_date_local' => $backdate,
    ]);

    $this->aggregator->rebuildForwardFrom($user, $backdate);

    $ctlAfter = (float) WeeklySnapshot::query()
        ->where('user_id', $user->id)
        ->where('week_ending', $latestWeekEnding)
        ->value('ctl_42d');

    // A cumulative CTL must carry the backdated load forward to the latest week.
    expect($ctlAfter)->toBeGreaterThan($ctlBefore);
});

it('measures the in-progress week CTL as-of today, not the future Sunday', function (): void {
    // setTestNow is a Monday, so the current week's Sunday is in the future.
    // Steady load up to today should leave CTL near convergence; rolling the
    // EWMA out to Sunday would zero-fill the unseen days and deflate it.
    $user = User::factory()->create();
    for ($i = 0; $i < 200; $i++) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => 8000,
            'moving_time' => 2400,
            'elapsed_time' => 2400,
            'trimp_edwards' => 80.0,
            'start_date_local' => Carbon::today()->subDays(199 - $i),
        ]);
    }

    $snap = $this->aggregator->rebuildForwardFrom($user, Carbon::today());

    expect($snap)->not->toBeNull()
        ->and((float) $snap->ctl_42d)->toBeGreaterThan(75.0);
});

it('rebuildForwardFrom returns the anchor week snapshot', function (): void {
    $user = User::factory()->create();
    $anchor = Carbon::today()->subDays(21);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8000,
        'moving_time' => 2400,
        'elapsed_time' => 2400,
        'trimp_edwards' => 80.0,
        'start_date_local' => $anchor,
    ]);

    $snap = $this->aggregator->rebuildForwardFrom($user, $anchor);

    expect($snap)->toBeInstanceOf(WeeklySnapshot::class)
        ->and($snap->week_ending->toDateString())
        ->toBe($anchor->copy()->endOfWeek(Carbon::SUNDAY)->toDateString());
});

it('projects only the columns the roll-up reads, never the whole detail row', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8000,
        'moving_time' => 2400,
        'elapsed_time' => 2400,
        'trimp_edwards' => 80.0,
        'start_date_local' => Carbon::today()->subDays(7),
        'stream_summary' => ['decoupling_pct' => 3.0],
        'splits_metric' => [['distance' => 1000, 'moving_time' => 300]],
    ]);

    $selects = [];
    DB::listen(function (QueryExecuted $query) use (&$selects): void {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from `activity_details`')) {
            $selects[] = $query->sql;
        }
    });

    $this->aggregator->rebuildForwardFrom($user, Carbon::today()->subDays(7));

    expect($selects)->not->toBeEmpty();
    foreach ($selects as $sql) {
        expect($sql)->not->toContain('`activity_details`.*')
            ->and($sql)->not->toContain('splits_metric')
            ->and($sql)->toContain('`activity_details`.`stream_summary`')
            ->and($sql)->toContain('`activity_details`.`trimp_edwards`');
    }
});

it('still computes decoupling and sums from the narrowed projection', function (): void {
    $user = User::factory()->create();
    foreach ([['dist' => 8000, 'dec' => 2.0], ['dist' => 6000, 'dec' => 6.0]] as $cfg) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => $cfg['dist'],
            'moving_time' => 2400,
            'elapsed_time' => 2400,
            'trimp_edwards' => 60.0,
            'start_date_local' => Carbon::today(),
            'stream_summary' => ['decoupling_pct' => $cfg['dec']],
            'splits_metric' => [['distance' => 1000, 'moving_time' => 300]],
        ]);
    }

    $snapshot = $this->aggregator->rebuildForwardFrom($user, Carbon::today());

    expect($snapshot)->not->toBeNull()
        ->and((float) $snapshot->distance_km)->toBe(14.0)
        ->and($snapshot->runs)->toBe(2)
        ->and($snapshot->elapsed_time_sec)->toBe(4800)
        ->and($snapshot->avg_decoupling)->toBe(4.0);
});

it('drops the caches derived from the history it just rebuilt', function (string $rebuild): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8000,
        'moving_time' => 2400,
        'elapsed_time' => 2400,
        'trimp_edwards' => 60.0,
        'start_date_local' => Carbon::today(),
    ]);
    $today = Carbon::today()->toDateString();
    Cache::put(PastYouTrendBuilder::cacheKey($user->id, $today), ['verdict' => 'stale']);
    Cache::put(TrainingLoad::summaryCacheKey($user->id, $today, 7), ['stale']);
    Cache::put(UsualRunTime::cacheKey($user->id, $today), 999);

    match ($rebuild) {
        'rebuildFor' => $this->aggregator->rebuildFor($user),
        'rollForwardFrom' => $this->aggregator->rollForwardFrom($user, Carbon::today()),
        'rebuildForwardFrom' => $this->aggregator->rebuildForwardFrom($user, Carbon::today()),
    };

    expect(Cache::has(PastYouTrendBuilder::cacheKey($user->id, $today)))->toBeFalse()
        ->and(Cache::has(TrainingLoad::summaryCacheKey($user->id, $today, 7)))->toBeFalse()
        ->and(Cache::has(UsualRunTime::cacheKey($user->id, $today)))->toBeFalse();
})->with(['rebuildFor', 'rollForwardFrom', 'rebuildForwardFrom']);

it('replays the snapshot saved hooks its upsert skips', function (string $rebuild): void {
    $user = User::factory()->create(['streak_settled_through' => Carbon::today()]);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8000,
        'elapsed_time' => 2400,
        'trimp_edwards' => 60.0,
        'start_date_local' => Carbon::today()->subDays(7),
    ]);
    $trailingWeeks = app(ResolveTrailingWeeksAction::class);
    $today = Carbon::today()->toDateString();
    expect($trailingWeeks($user->id, $today, 6))->toBeEmpty();

    match ($rebuild) {
        'rebuildFor' => $this->aggregator->rebuildFor($user),
        'rollForwardFrom' => $this->aggregator->rollForwardFrom($user, Carbon::today()->subDays(7)),
        'rebuildForwardFrom' => $this->aggregator->rebuildForwardFrom($user, Carbon::today()->subDays(7)),
    };

    expect($trailingWeeks($user->id, $today, 6)->pluck('runs')->all())->toContain(1)
        ->and($user->fresh()?->streak_settlement_dirty_from?->toDateString())->toBe('2026-05-10');
})->with(['rebuildFor', 'rollForwardFrom', 'rebuildForwardFrom']);

it('keeps created_at and casts on a rewritten week while moving updated_at', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8000,
        'elapsed_time' => 2400,
        'trimp_edwards' => 60.0,
        'start_date_local' => Carbon::today()->subDays(7),
    ]);

    $this->aggregator->rebuildForwardFrom($user, Carbon::today()->subDays(7));
    Carbon::setTestNow('2026-05-11 13:00:00');
    $snapshot = $this->aggregator->rebuildForwardFrom($user, Carbon::today()->subDays(7));

    expect($snapshot)->not->toBeNull()
        ->and($snapshot?->created_at?->toDateTimeString())->toBe('2026-05-11 12:00:00')
        ->and($snapshot?->updated_at?->toDateTimeString())->toBe('2026-05-11 13:00:00')
        ->and($snapshot?->week_ending->toDateString())->toBe('2026-05-10')
        ->and($snapshot?->runs)->toBe(1)
        ->and($snapshot?->elapsed_time_sec)->toBe(2400)
        ->and($snapshot?->distance_km)->toBe(8.0)
        ->and($snapshot?->weekly_trimp)->toBe(60.0)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('waits for the athlete lock and gives up while another rebuild holds it', function (string $rebuild): void {
    Sleep::fake(syncWithCarbon: true);
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8000,
        'elapsed_time' => 2400,
        'trimp_edwards' => 60.0,
        'start_date_local' => Carbon::today()->subDays(7),
    ]);
    $run = fn (): mixed => match ($rebuild) {
        'rebuildFor' => $this->aggregator->rebuildFor($user),
        'rollForwardFrom' => $this->aggregator->rollForwardFrom($user, Carbon::today()->subDays(7)),
        'rebuildForwardFrom' => $this->aggregator->rebuildForwardFrom($user, Carbon::today()->subDays(7)),
    };
    $held = Cache::lock(WeeklyAggregator::lockKey($user->id), 300);
    $held->get();
    $startedAt = Carbon::now();

    expect($run)->toThrow(LockTimeoutException::class)
        ->and($startedAt->diffInSeconds(Carbon::now()))->toBeGreaterThan(19.0)->toBeLessThanOrEqual(20.0)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->exists())->toBeFalse();

    $held->release();
    $run();

    expect(WeeklySnapshot::query()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(Cache::lock(WeeklyAggregator::lockKey($user->id), 1)->get())->toBeTrue();
})->with(['rebuildFor', 'rollForwardFrom', 'rebuildForwardFrom']);

it('holds the athlete lock until the surrounding transaction commits', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8000,
        'elapsed_time' => 2400,
        'trimp_edwards' => 60.0,
        'start_date_local' => Carbon::today()->subDays(7),
    ]);
    $lockFree = fn (): bool => tap(Cache::lock(WeeklyAggregator::lockKey($user->id), 1), fn ($probe) => $probe->get() && $probe->release())->get();
    $heldInside = null;

    DB::transaction(function () use ($user, $lockFree, &$heldInside): void {
        $this->aggregator->rebuildForwardFrom($user, Carbon::today()->subDays(7));
        $heldInside = ! $lockFree();
    });

    expect($heldInside)->toBeTrue()
        ->and(Cache::lock(WeeklyAggregator::lockKey($user->id), 1)->get())->toBeTrue();
});

it('releases the athlete lock when the surrounding transaction rolls back', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8000,
        'elapsed_time' => 2400,
        'trimp_edwards' => 60.0,
        'start_date_local' => Carbon::today()->subDays(7),
    ]);

    expect(fn () => DB::transaction(function () use ($user): void {
        $this->aggregator->rebuildForwardFrom($user, Carbon::today()->subDays(7));

        throw new RuntimeException('caller failed after the rebuild');
    }))->toThrow(RuntimeException::class);

    expect(Cache::lock(WeeklyAggregator::lockKey($user->id), 1)->get())->toBeTrue();
});

it('lets a second rebuild in the same transaction reuse the athlete lock it already holds', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8000,
        'elapsed_time' => 2400,
        'trimp_edwards' => 60.0,
        'start_date_local' => Carbon::today()->subDays(7),
    ]);

    DB::transaction(function () use ($user): void {
        $this->aggregator->rebuildForwardFrom($user, Carbon::today()->subDays(7));
        $this->aggregator->rebuildFor($user);
    });

    expect(WeeklySnapshot::query()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(Cache::lock(WeeklyAggregator::lockKey($user->id), 1)->get())->toBeTrue();
});

it('keeps the earliest dirty week however the marks arrive', function (): void {
    $user = User::factory()->create();

    $this->aggregator->markDirty($user, Carbon::parse('2026-04-15'));
    $this->aggregator->markDirty($user, Carbon::parse('2026-03-03'));
    $this->aggregator->markDirty($user, Carbon::parse('2026-04-29'));

    expect($user->fresh()->weekly_snapshots_dirty_from->toDateString())->toBe('2026-03-08');
});

it('rolls forward from the earliest dirty week and clears the mark', function (): void {
    $user = User::factory()->create();
    foreach (['2026-03-03', '2026-04-15'] as $day) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => 8000,
            'elapsed_time' => 2400,
            'trimp_edwards' => 60.0,
            'start_date_local' => Carbon::parse($day.' 07:00'),
        ]);
    }
    $this->aggregator->markDirty($user, Carbon::parse('2026-04-15'));
    $this->aggregator->markDirty($user, Carbon::parse('2026-03-03'));

    $this->aggregator->rollForwardDirty($user);

    expect(WeeklySnapshot::query()->where('user_id', $user->id)->min('week_ending'))->toBe('2026-03-08')
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->max('week_ending'))->toBe('2026-05-17')
        ->and($user->fresh()->weekly_snapshots_dirty_from)->toBeNull();
});

it('writes nothing when no week is dirty', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()]);

    $this->aggregator->rollForwardDirty($user);

    expect(WeeklySnapshot::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('rolls forward from whichever is earlier, the given run or a week already dirty', function (): void {
    $user = User::factory()->create();
    foreach (['2026-03-03', '2026-04-15'] as $day) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse($day.' 07:00')]);
    }
    $this->aggregator->markDirty($user, Carbon::parse('2026-03-03'));

    $this->aggregator->rollForwardFrom($user, Carbon::parse('2026-04-15'));

    expect(WeeklySnapshot::query()->where('user_id', $user->id)->min('week_ending'))->toBe('2026-03-08')
        ->and($user->fresh()->weekly_snapshots_dirty_from)->toBeNull();
});

it('leaves the week dirty when the roll forward dies mid-rebuild', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse('2026-04-15 07:00')]);
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'insert into `weekly_snapshots`')) {
            throw new RuntimeException('worker killed');
        }
    });

    expect(fn () => $this->aggregator->rollForwardFrom($user, Carbon::parse('2026-04-15')))->toThrow(RuntimeException::class)
        ->and($user->fresh()->weekly_snapshots_dirty_from->toDateString())->toBe('2026-04-19');
});

function seedWeeklyAggregatorMultiYearHistory(User $user): void
{
    $first = Carbon::parse('2023-01-02');
    $days = (int) $first->diffInDays(Carbon::today());
    $details = [];
    for ($d = 0; $d <= $days; $d++) {
        if (($d >= 400 && $d < 440) || ($d % 4 !== 0 && $d % 9 !== 5)) {
            continue;
        }
        $starts = [$first->copy()->addDays($d)->setTime($d % 13 === 0 ? 23 : 6 + ($d % 5) * 3, $d % 13 === 0 ? 45 : 10)];
        if ($d % 10 === 2) {
            $starts[] = $first->copy()->addDays($d)->setTime(18, 30);
        }
        foreach ($starts as $n => $start) {
            $summary = match (true) {
                $d % 6 === 0 => ['decoupling_pct' => ($d % 17) - 3.25, 'drift_metric_version' => 2, 'steady_effort_decoupling_pct' => ($d % 13) * 0.7],
                $d % 3 === 0 => ['decoupling_pct' => ($d % 11) * 0.45],
                default => null,
            };
            $details[] = [
                'distance' => 3000 + ($d * 271 + $n * 97) % 15000 + 0.5,
                'moving_time' => 1200 + ($d * 53) % 4000,
                'elapsed_time' => 1260 + ($d * 53 + $n * 11) % 4000,
                'trimp_edwards' => $d < 60 || $d % 11 === 0 ? null : 30 + ($d * 37 + $n * 5) % 90 + 0.3,
                'start_date_local' => $start->toDateTimeString(),
                'stream_summary' => $summary === null ? null : json_encode($summary, JSON_THROW_ON_ERROR),
                'has_heartrate' => true,
            ];
        }
    }

    $now = Carbon::now();
    foreach (array_chunk(array_keys($details), 500) as $chunk) {
        DB::table('activities')->insert(array_map(fn (int $i): array => [
            'user_id' => $user->id,
            'strava_external_id' => 1_000_000_000 + $i,
            'fetched_at' => $now,
            'analyzed_at' => $now,
            'ingest_state' => IngestState::Detailed->value,
            'detail_fail_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }

    $activityIds = DB::table('activities')->where('user_id', $user->id)->orderBy('id')->pluck('id')->all();
    foreach (array_chunk($details, 500, preserve_keys: true) as $chunk) {
        $rows = [];
        foreach ($chunk as $i => $detail) {
            $rows[] = [...$detail, 'activity_id' => $activityIds[$i], 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('activity_details')->insert($rows);
    }
}

function weeklySnapshotFingerprint(User $user): string
{
    $rows = DB::table('weekly_snapshots')
        ->where('user_id', $user->id)
        ->orderBy('week_ending')
        ->get(['week_ending', 'distance_km', 'runs', 'elapsed_time_sec', 'weekly_trimp', 'atl_7d', 'ctl_42d', 'form', 'form_status', 'avg_decoupling', 'avg_decoupling_v2', 'monotony', 'strain']);

    return \count($rows).':'.hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}

it('rebuilds a multi-year history into the same snapshot rows', function (Closure $rebuild, string $fingerprint): void {
    $user = User::factory()->create();
    seedWeeklyAggregatorMultiYearHistory($user);

    $rebuild($this->aggregator, $user);

    expect(weeklySnapshotFingerprint($user))->toBe($fingerprint);
})->with([
    'full rebuild' => [fn (WeeklyAggregator $aggregator, User $user) => $aggregator->rebuildFor($user), '176:ada7ed55b2209046190ff26f249f2b37441b7fdf58022e833159663e2a0e23d0'],
    'forward from two years back' => [fn (WeeklyAggregator $aggregator, User $user) => $aggregator->rebuildForwardFrom($user, Carbon::today()->subWeeks(104)->addDays(3)), '105:93e16f873e952efcbcc66961a6cd85a3a58a7184240ad2451e299f71d21d26b2'],
]);

it('finishes a rebuild that crosses midnight partway through', function (): void {
    $user = User::factory()->create();
    foreach ([9, 3, 1] as $daysAgo) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => 60.0,
            'start_date_local' => Carbon::today()->subDays($daysAgo)->setTime(7, 0),
        ]);
    }
    $beforeMidnight = Carbon::parse('2026-05-12 23:59:59');
    $afterMidnight = Carbon::parse('2026-05-13 00:00:01');

    $calls = 0;
    Carbon::setTestNow(function () use (&$calls, $beforeMidnight): Carbon {
        $calls++;

        return $beforeMidnight->copy();
    });
    $this->aggregator->rebuildFor($user);
    $totalCalls = $calls;

    $failures = [];
    for ($crossAfter = 1; $crossAfter < $totalCalls; $crossAfter++) {
        $calls = 0;
        Carbon::setTestNow(function () use (&$calls, $crossAfter, $beforeMidnight, $afterMidnight): Carbon {
            return ++$calls <= $crossAfter ? $beforeMidnight->copy() : $afterMidnight->copy();
        });

        try {
            $this->aggregator->rebuildFor($user);
        } catch (Throwable $e) {
            $failures[] = "{$crossAfter}: {$e->getMessage()}";
        }
    }

    expect($totalCalls)->toBeGreaterThan(1)
        ->and($failures)->toBe([]);
});
