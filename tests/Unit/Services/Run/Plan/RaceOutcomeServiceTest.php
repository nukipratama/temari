<?php

declare(strict_types=1);

use App\Enums\RaceChangeKind;
use App\Enums\RaceOutcome;
use App\Enums\SeasonPerformance;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PerformanceEvidence;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\User;
use App\Services\Gamification\SeasonRecordBuilder;
use App\Services\Run\Plan\RaceOutcomeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 09:00:00');
});
afterEach(fn () => Carbon::setTestNow());

function raceOutcomeService(): RaceOutcomeService
{
    return app(RaceOutcomeService::class);
}

function pendingRaceOf(User $user): RaceGoal
{
    return RaceGoal::factory()->for($user)->completed()->create([
        'race_date' => '2026-10-04', 'distance_m' => 10_000, 'goal_time_sec' => 3_000, 'outcome' => RaceOutcome::Pending,
    ]);
}

function raceDayRun(User $user, float $distance = 10_040.0, int $elapsed = 2_950, string $startsAt = '2026-10-04 07:00:00'): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => $startsAt, 'distance' => $distance, 'elapsed_time' => $elapsed, 'moving_time' => $elapsed]);

    return $activity;
}

it('confirms a matching owned activity, feeds fitness evidence from it and regenerates the plan, and takes a repeat as a no-op', function (): void {
    $user = User::factory()->create();
    $goal = pendingRaceOf($user);
    $run = raceDayRun($user);

    $race = raceOutcomeService()->record($user, $goal, RaceOutcome::Confirmed, activityId: $run->id);

    $evidence = PerformanceEvidence::query()->sole();
    $change = nonNull($race->changes->last());
    expect($race->outcome)->toBe(RaceOutcome::Confirmed)
        ->and($race->outcome_activity_id)->toBe($run->id)
        ->and($race->finish_time_sec)->toBe(2_950)
        ->and($race->outcome_recorded_at)->not->toBeNull()
        ->and($evidence->kind->value)->toBe('race')
        ->and($evidence->activity_id)->toBe($run->id)
        ->and($evidence->race_goal_id)->toBe($race->id)
        ->and($evidence->distance_m)->toBe(10_040)
        ->and($evidence->elapsed_time_sec)->toBe(2_950)
        ->and($evidence->performed_on->toDateString())->toBe('2026-10-04')
        ->and($change->kind)->toBe(RaceChangeKind::Outcome)
        ->and($change->outcome)->toBe(RaceOutcome::Confirmed);

    $plannedAfterConfirm = PlannedSession::query()->where('user_id', $user->id)->count();
    PlannedSession::query()->where('user_id', $user->id)->delete();
    raceOutcomeService()->record($user, $goal->refresh(), RaceOutcome::Confirmed, activityId: $run->id);

    expect($goal->refresh()->changes()->count())->toBe(1)
        ->and(PerformanceEvidence::query()->count())->toBe(1)
        ->and($plannedAfterConfirm)->toBeGreaterThan(0)
        ->and(PlannedSession::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('rejects an implausible manual time without recording anything', function (): void {
    $user = User::factory()->create();
    $goal = pendingRaceOf($user);
    expect(fn () => raceOutcomeService()->record($user, $goal, RaceOutcome::Confirmed, finishTimeSec: 600))
        ->toThrow(ValidationException::class);

    expect($goal->refresh()->outcome)->toBe(RaceOutcome::Pending)
        ->and($goal->changes()->count())->toBe(0)
        ->and(PerformanceEvidence::query()->count())->toBe(0);
});

it('refuses an activity that is another athlete\'s, off race day or the wrong distance', function (Closure $activity): void {
    $user = User::factory()->create();
    $goal = pendingRaceOf($user);
    $id = $activity($user)->id;

    expect(fn () => raceOutcomeService()->record($user, $goal, RaceOutcome::Confirmed, activityId: $id))
        ->toThrow(ValidationException::class);
    expect($goal->refresh()->outcome)->toBe(RaceOutcome::Pending);
})->with([
    'another athlete' => [fn (User $user) => raceDayRun(User::factory()->create())],
    'another day' => [fn (User $user) => raceDayRun($user, startsAt: '2026-10-03 07:00:00')],
    'a warm-up distance' => [fn (User $user) => raceDayRun($user, distance: 2_000.0, elapsed: 700)],
]);

it('needs exactly one of an activity or a time to confirm', function (): void {
    $user = User::factory()->create();
    $goal = pendingRaceOf($user);
    $run = raceDayRun($user);

    expect(fn () => raceOutcomeService()->record($user, $goal, RaceOutcome::Confirmed))->toThrow(ValidationException::class)
        ->and(fn () => raceOutcomeService()->record($user, $goal, RaceOutcome::Confirmed, activityId: $run->id, finishTimeSec: 3_000))->toThrow(ValidationException::class);
});

it('records did not run and cancelled without any result or evidence', function (RaceOutcome $outcome): void {
    $user = User::factory()->create();
    $goal = pendingRaceOf($user);
    $race = raceOutcomeService()->record($user, $goal, $outcome);

    expect($race->outcome)->toBe($outcome)
        ->and($race->finish_time_sec)->toBeNull()
        ->and($race->outcome_activity_id)->toBeNull()
        ->and(PerformanceEvidence::query()->count())->toBe(0);
})->with([RaceOutcome::DidNotRun, RaceOutcome::Cancelled]);

it('confirms a manual finish time against the race distance, then lets the athlete correct any state later, recording each change and retracting stale evidence', function (): void {
    $user = User::factory()->create();
    $goal = pendingRaceOf($user);
    $race = raceOutcomeService()->record($user, $goal, RaceOutcome::Confirmed, finishTimeSec: 3_100);

    $evidence = PerformanceEvidence::query()->sole();
    expect($race->finish_time_sec)->toBe(3_100)
        ->and($race->outcome_activity_id)->toBeNull()
        ->and($evidence->distance_m)->toBe(10_000)
        ->and($evidence->elapsed_time_sec)->toBe(3_100)
        ->and($evidence->activity_id)->toBeNull();

    raceOutcomeService()->record($user, $goal->refresh(), RaceOutcome::Confirmed, finishTimeSec: 3_050);
    expect(PerformanceEvidence::query()->sole()->elapsed_time_sec)->toBe(3_050);

    raceOutcomeService()->record($user, $goal->refresh(), RaceOutcome::DidNotRun);
    expect(PerformanceEvidence::query()->count())->toBe(0)
        ->and($goal->refresh()->finish_time_sec)->toBeNull();

    $reopened = raceOutcomeService()->record($user, $goal->refresh(), RaceOutcome::Pending);

    expect($reopened->outcome)->toBe(RaceOutcome::Pending)
        ->and($reopened->changes->pluck('outcome')->all())->toBe([RaceOutcome::Confirmed, RaceOutcome::Confirmed, RaceOutcome::DidNotRun, RaceOutcome::Pending]);
});

it('accepts a late confirmation long after the date', function (): void {
    $user = User::factory()->create();
    $goal = pendingRaceOf($user);
    Carbon::setTestNow('2026-12-20 09:00:00');

    $race = raceOutcomeService()->record($user, $goal, RaceOutcome::Confirmed, finishTimeSec: 3_000);

    expect($race->outcome)->toBe(RaceOutcome::Confirmed);
});

it('does not take an outcome for a race that has not happened yet', function (): void {
    $user = User::factory()->create();
    pendingRaceOf($user);
    $future = RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-20', 'outcome' => RaceOutcome::Pending]);

    expect(fn () => raceOutcomeService()->record($user, $future, RaceOutcome::DidNotRun))->toThrow(ValidationException::class);
    expect($future->refresh()->outcome)->toBe(RaceOutcome::Pending);
});

it('retires an active race whose passed date is confirmed before the nightly close', function (): void {
    $user = User::factory()->create();
    pendingRaceOf($user);
    $active = RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-05', 'outcome' => RaceOutcome::Pending]);

    $race = raceOutcomeService()->record($user, $active, RaceOutcome::Confirmed, finishTimeSec: 3_000);

    expect($race->completed_at)->not->toBeNull();
});

it('keeps a result beyond the evidence range on the race without feeding fitness', function (): void {
    $user = User::factory()->create();
    pendingRaceOf($user);
    $ultra = RaceGoal::factory()->for($user)->completed()->create([
        'race_date' => '2026-10-04', 'distance_m' => 80_000, 'goal_time_sec' => 36_000, 'outcome' => RaceOutcome::Pending,
    ]);

    $race = raceOutcomeService()->record($user, $ultra, RaceOutcome::Confirmed, finishTimeSec: 40_000);

    expect($race->finish_time_sec)->toBe(40_000)
        ->and(PerformanceEvidence::query()->count())->toBe(0);
});

it('feeds a rounded 42.2 km marathon to fitness at the marathon distance', function (): void {
    $user = User::factory()->create();
    pendingRaceOf($user);
    $marathon = RaceGoal::factory()->for($user)->completed()->create([
        'race_date' => '2026-10-04', 'distance_m' => 42_200, 'goal_time_sec' => 13_000, 'outcome' => RaceOutcome::Pending,
    ]);

    $race = raceOutcomeService()->record($user, $marathon, RaceOutcome::Confirmed, finishTimeSec: 13_200);

    expect($race->finish_time_sec)->toBe(13_200)
        ->and(PerformanceEvidence::query()->sole()->distance_m)->toBe(42_195);
});

it('refuses another athlete\'s race', function (): void {
    $user = User::factory()->create();
    $goal = pendingRaceOf($user);
    $intruder = User::factory()->create();

    expect(fn () => raceOutcomeService()->record($intruder, $goal, RaceOutcome::DidNotRun))->toThrow(AuthorizationException::class);
    expect($goal->refresh()->outcome)->toBe(RaceOutcome::Pending);
});

it('carries a late confirmation into the performance of the season that was already settled', function (): void {
    $user = User::factory()->create();
    $goal = pendingRaceOf($user);
    $season = Season::factory()->for($user)->create(['race_goal_id' => $goal->id, 'starts_at' => '2026-09-01', 'ends_at' => '2026-10-04']);
    app(SeasonRecordBuilder::class)->settle($user, $season->load('goals', 'raceGoal'), Carbon::today());
    expect($season->refresh()->performance_state)->toBe(SeasonPerformance::Pending);

    raceOutcomeService()->record($user, $goal, RaceOutcome::Confirmed, finishTimeSec: 3_100);
    expect($season->refresh()->performance_state)->toBe(SeasonPerformance::Met);

    raceOutcomeService()->record($user, $goal->refresh(), RaceOutcome::DidNotRun);
    expect($season->refresh()->performance_state)->toBe(SeasonPerformance::DidNotRun);
});
