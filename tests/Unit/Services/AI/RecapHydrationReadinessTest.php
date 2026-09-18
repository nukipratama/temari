<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StravaConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\RecapHydrationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// Monday 2026-05-18; the week ending Sunday 2026-05-17 closed 5 hours ago, so
// its 48-hour grace runs out at Wednesday 2026-05-20 00:00.
beforeEach(function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** A run in the week ending 2026-05-17, in whichever ingest state $state names. */
function hydrationRun(User $user, string $state, string $date = '2026-05-14', int $failCount = 0): Activity
{
    $activity = Activity::factory()->for($user)->{$state}()->create(['detail_fail_count' => $failCount]);
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse($date)]);

    return $activity;
}

function hydrationWeekOf(User $user, string $weekEnding = '2026-05-17'): WeeklySnapshot
{
    return WeeklySnapshot::factory()->for($user)->create(['week_ending' => $weekEnding, 'runs' => 3]);
}

/** @return list<int> */
function hydrationReadyIds(WeeklySnapshot ...$snapshots): array
{
    return app(RecapHydrationReadiness::class)
        ->ready(collect($snapshots))
        ->pluck('id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();
}

it('holds back a week that still holds a summary-only run', function (): void {
    $user = User::factory()->create();
    $week = hydrationWeekOf($user);
    hydrationRun($user, 'summaryOnly');

    expect(hydrationReadyIds($week))->toBe([]);
});

it('holds back a week that still holds an un-ingested stub', function (): void {
    $user = User::factory()->create();
    $week = hydrationWeekOf($user);
    hydrationRun($user, 'stub');

    expect(hydrationReadyIds($week))->toBe([]);
});

it('releases a week whose runs are all detailed', function (): void {
    $user = User::factory()->create();
    $week = hydrationWeekOf($user);
    hydrationRun($user, 'analyzed');

    expect(hydrationReadyIds($week))->toBe([$week->id]);
});

it('releases a week whose only backlog is a run the pipeline has given up on', function (): void {
    $user = User::factory()->create();
    $week = hydrationWeekOf($user);
    hydrationRun($user, 'summaryOnly', failCount: Activity::MAX_DETAIL_FETCH_ATTEMPTS);

    expect(hydrationReadyIds($week))->toBe([$week->id]);
});

it('holds back only the week the un-hydrated run falls in', function (): void {
    $user = User::factory()->create();
    $blocked = hydrationWeekOf($user);
    $clear = hydrationWeekOf($user, '2026-05-10');
    hydrationRun($user, 'summaryOnly', date: '2026-05-14');

    expect(hydrationReadyIds($blocked, $clear))->toBe([$clear->id]);
});

it('holds back only the athlete who has the backlog', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $myWeek = hydrationWeekOf($mine);
    $theirWeek = hydrationWeekOf($theirs);
    hydrationRun($mine, 'summaryOnly');

    expect(hydrationReadyIds($myWeek, $theirWeek))->toBe([$theirWeek->id]);
});

it('releases a still-unhydrated week once the grace window has run out', function (): void {
    $user = User::factory()->create();
    $week = hydrationWeekOf($user);
    hydrationRun($user, 'summaryOnly');

    Carbon::setTestNow('2026-05-20 00:01:00');

    expect(hydrationReadyIds($week))->toBe([$week->id]);
});

it('measures the grace window from the connection when the athlete has just connected', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => '2026-05-19 12:00:00']);
    $week = hydrationWeekOf($user);
    hydrationRun($user, 'summaryOnly');

    // Past Wednesday 00:00, but only 12 hours past a connection made Tuesday noon.
    Carbon::setTestNow('2026-05-20 00:01:00');
    expect(hydrationReadyIds($week))->toBe([]);

    Carbon::setTestNow('2026-05-21 12:01:00');
    expect(hydrationReadyIds($week))->toBe([$week->id]);
});

it('honours a configured grace window', function (): void {
    config()->set('ai.recap_hydration_grace_hours', 1);

    $user = User::factory()->create();
    $week = hydrationWeekOf($user);
    hydrationRun($user, 'summaryOnly');

    expect(hydrationReadyIds($week))->toBe([$week->id]);
});

it('records the deferral and the escape hatch in the structured log', function (): void {
    Log::spy();

    $user = User::factory()->create();
    $week = hydrationWeekOf($user);
    hydrationRun($user, 'summaryOnly');

    hydrationReadyIds($week);
    Log::shouldHaveReceived('info')
        ->with('narrator.recap.hydration_deferred', Mockery::on(
            fn (array $context): bool => $context['count'] === 1 && $context['weeks'] === [$user->id.'|2026-05-17'],
        ))
        ->once();

    Carbon::setTestNow('2026-05-20 00:01:00');

    hydrationReadyIds($week);
    Log::shouldHaveReceived('info')
        ->with('narrator.recap.hydration_grace_expired', Mockery::on(
            fn (array $context): bool => $context['count'] === 1,
        ))
        ->once();
});

it('short-circuits on an empty set', function (): void {
    expect(hydrationReadyIds())->toBe([]);
});

/** @return list<string> */
function hydrationReadyMonths(User $user, string ...$months): array
{
    return app(RecapHydrationReadiness::class)->readyMonths($user->id, collect($months))->all();
}

it('holds back a month that still holds a summary-only run, and only that month', function (): void {
    // The 1st-of-month sweep, 5h45m after May closed.
    Carbon::setTestNow('2026-06-01 05:45:00');
    $user = User::factory()->create();
    hydrationRun($user, 'summaryOnly', date: '2026-05-29');
    hydrationRun($user, 'analyzed', date: '2026-04-20');

    expect(hydrationReadyMonths($user, '2026-04', '2026-05'))->toBe(['2026-04']);
});

it('releases a month whose runs are all detailed, or whose backlog belongs to another athlete', function (): void {
    Carbon::setTestNow('2026-06-01 05:45:00');
    $user = User::factory()->create();
    hydrationRun($user, 'analyzed', date: '2026-05-29');
    hydrationRun(User::factory()->create(), 'summaryOnly', date: '2026-05-29');

    expect(hydrationReadyMonths($user, '2026-05'))->toBe(['2026-05']);
});

it('releases a still-unhydrated month once the grace window after its close has run out', function (): void {
    $user = User::factory()->create();
    hydrationRun($user, 'summaryOnly', date: '2026-05-29');

    Carbon::setTestNow('2026-06-02 23:59:00');
    expect(hydrationReadyMonths($user, '2026-05'))->toBe([]);

    Carbon::setTestNow('2026-06-03 00:01:00');
    expect(hydrationReadyMonths($user, '2026-05'))->toBe(['2026-05']);
});

it('measures a month\'s grace window from the connection when that came later', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => '2026-06-05 12:00:00']);
    hydrationRun($user, 'summaryOnly', date: '2026-05-29');

    Carbon::setTestNow('2026-06-07 11:59:00');
    expect(hydrationReadyMonths($user, '2026-05'))->toBe([]);

    Carbon::setTestNow('2026-06-07 12:01:00');
    expect(hydrationReadyMonths($user, '2026-05'))->toBe(['2026-05']);
});

it('records a deferred month in the structured log', function (): void {
    Log::spy();
    Carbon::setTestNow('2026-06-01 05:45:00');
    $user = User::factory()->create();
    hydrationRun($user, 'summaryOnly', date: '2026-05-29');

    hydrationReadyMonths($user, '2026-05');

    Log::shouldHaveReceived('info')
        ->with('narrator.recap.hydration_deferred', ['count' => 1, 'months' => [$user->id.'|2026-05']])
        ->once();
});
