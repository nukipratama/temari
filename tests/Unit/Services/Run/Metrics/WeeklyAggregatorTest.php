<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\WeeklyAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-05-11 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('returns 0 and creates no rows when the user has no analyzed runs', function (): void {
    $user = User::factory()->create();

    expect(app(WeeklyAggregator::class)->rebuildFor($user))->toBe(0)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('upserts one snapshot per ISO week from first run through today', function (): void {
    $user = User::factory()->create();
    foreach ([21, 14, 7, 0] as $daysAgo) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => 8000,
            'moving_time' => 2400,
            'trimp_edwards' => 60.0,
            'start_date_local' => Carbon::today()->subDays($daysAgo),
            'stream_summary' => ['decoupling_pct' => 3.0],
        ]);
    }

    $written = app(WeeklyAggregator::class)->rebuildFor($user);

    // Frozen at 2026-05-11 (Mon); 21 days back spans 4 ISO weeks ending Sunday.
    expect($written)->toBe(4)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe(4);
});

it('aggregates distance, runs, and avg decoupling per week', function (): void {
    $user = User::factory()->create();
    $weekEnding = Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay();

    foreach ([['distance' => 6000, 'dec' => 2.0], ['distance' => 10000, 'dec' => 5.0]] as $cfg) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => $cfg['distance'],
            'moving_time' => 1800,
            'trimp_edwards' => 50.0,
            'start_date_local' => $weekEnding->copy()->subDays(2),
            'stream_summary' => ['decoupling_pct' => $cfg['dec']],
        ]);
    }

    app(WeeklyAggregator::class)->rebuildFor($user);
    $snapshot = WeeklySnapshot::query()
        ->where('user_id', $user->id)
        ->where('week_ending', $weekEnding->toDateString())
        ->firstOrFail();

    expect($snapshot->distance_km)->toBe(16.0)
        ->and($snapshot->runs)->toBe(2)
        ->and($snapshot->avg_decoupling)->toBe(3.5);
});

it('writes null avg_decoupling when no runs in the week have decoupling_pct', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'moving_time' => 1500,
        'trimp_edwards' => 40.0,
        'start_date_local' => Carbon::today(),
        'stream_summary' => ['time_in_zone_min' => ['Z2' => 25]],
    ]);

    app(WeeklyAggregator::class)->rebuildFor($user);

    $snapshot = WeeklySnapshot::query()->where('user_id', $user->id)->latest('week_ending')->firstOrFail();
    expect($snapshot->avg_decoupling)->toBeNull();
});

it('skips details without trimp_edwards when rolling the daily TRIMP map', function (): void {
    // HR-less Strava run: counts toward distance/runs, contributes 0 TRIMP.
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'moving_time' => 1500,
        'trimp_edwards' => null,
        'start_date_local' => Carbon::today(),
    ]);

    app(WeeklyAggregator::class)->rebuildFor($user);

    $snapshot = WeeklySnapshot::query()->where('user_id', $user->id)->latest('week_ending')->firstOrFail();
    expect($snapshot->runs)->toBe(1)
        ->and($snapshot->distance_km)->toBe(5.0)
        ->and($snapshot->weekly_trimp)->toBe(0.0);
});

it('is idempotent — re-running upserts the same week without duplicating', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 7000,
        'moving_time' => 2000,
        'trimp_edwards' => 55.0,
        'start_date_local' => Carbon::today()->subDays(3),
    ]);

    app(WeeklyAggregator::class)->rebuildFor($user);
    $first = WeeklySnapshot::query()->where('user_id', $user->id)->count();

    app(WeeklyAggregator::class)->rebuildFor($user);
    $second = WeeklySnapshot::query()->where('user_id', $user->id)->count();

    expect($second)->toBe($first);
});

it('rebuildForWeekOf rebuilds only the snapshot covering the given date', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 6000,
        'moving_time' => 1800,
        'trimp_edwards' => 50.0,
        'start_date_local' => Carbon::today()->subDays(2),
    ]);

    $snap = app(WeeklyAggregator::class)->rebuildForWeekOf($user, Carbon::today()->subDays(2));

    expect($snap)->not->toBeNull()
        ->and($snap->user_id)->toBe($user->id)
        ->and(Carbon::parse($snap->week_ending)->toDateString())
            ->toBe(Carbon::today()->subDays(2)->endOfWeek(Carbon::SUNDAY)->toDateString())
        ->and((float) $snap->distance_km)->toBe(6.0);
});

it('rebuildForWeekOf returns null when user has no runs', function (): void {
    $user = User::factory()->create();

    $snap = app(WeeklyAggregator::class)->rebuildForWeekOf($user, Carbon::today());

    expect($snap)->toBeNull();
});
