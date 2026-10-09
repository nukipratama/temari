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
    $this->service = app(RaceOutcomeService::class);
    $this->user = User::factory()->create();
    $this->race = RaceGoal::factory()->for($this->user)->completed()->create([
        'race_date' => '2026-10-04', 'distance_m' => 10_000, 'goal_time_sec' => 3_000, 'outcome' => RaceOutcome::Pending,
    ]);
});
afterEach(fn () => Carbon::setTestNow());

function raceDayRun(User $user, float $distance = 10_040.0, int $elapsed = 2_950, string $startsAt = '2026-10-04 07:00:00'): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => $startsAt, 'distance' => $distance, 'elapsed_time' => $elapsed, 'moving_time' => $elapsed]);

    return $activity;
}

it('confirms a matching owned activity, feeds fitness evidence from it and regenerates the plan, and takes a repeat as a no-op', function (): void {
    $run = raceDayRun($this->user);

    $race = $this->service->record($this->user, $this->race, RaceOutcome::Confirmed, activityId: $run->id);

    $evidence = PerformanceEvidence::query()->sole();
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
        ->and($race->changes->last()->kind)->toBe(RaceChangeKind::Outcome)
        ->and($race->changes->last()->outcome)->toBe(RaceOutcome::Confirmed);

    $plannedAfterConfirm = PlannedSession::query()->where('user_id', $this->user->id)->count();
    PlannedSession::query()->where('user_id', $this->user->id)->delete();
    $this->service->record($this->user, $this->race->fresh(), RaceOutcome::Confirmed, activityId: $run->id);

    expect($this->race->fresh()->changes()->count())->toBe(1)
        ->and(PerformanceEvidence::query()->count())->toBe(1)
        ->and($plannedAfterConfirm)->toBeGreaterThan(0)
        ->and(PlannedSession::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('rejects an implausible manual time without recording anything', function (): void {
    expect(fn () => $this->service->record($this->user, $this->race, RaceOutcome::Confirmed, finishTimeSec: 600))
        ->toThrow(ValidationException::class);

    expect($this->race->fresh()->outcome)->toBe(RaceOutcome::Pending)
        ->and($this->race->changes()->count())->toBe(0)
        ->and(PerformanceEvidence::query()->count())->toBe(0);
});

it('refuses an activity that is another athlete\'s, off race day or the wrong distance', function (Closure $activity): void {
    $id = $activity($this->user)->id;

    expect(fn () => $this->service->record($this->user, $this->race, RaceOutcome::Confirmed, activityId: $id))
        ->toThrow(ValidationException::class);
    expect($this->race->fresh()->outcome)->toBe(RaceOutcome::Pending);
})->with([
    'another athlete' => [fn (User $user) => raceDayRun(User::factory()->create())],
    'another day' => [fn (User $user) => raceDayRun($user, startsAt: '2026-10-03 07:00:00')],
    'a warm-up distance' => [fn (User $user) => raceDayRun($user, distance: 2_000.0, elapsed: 700)],
]);

it('needs exactly one of an activity or a time to confirm', function (): void {
    $run = raceDayRun($this->user);

    expect(fn () => $this->service->record($this->user, $this->race, RaceOutcome::Confirmed))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->record($this->user, $this->race, RaceOutcome::Confirmed, activityId: $run->id, finishTimeSec: 3_000))->toThrow(ValidationException::class);
});

it('records did not run and cancelled without any result or evidence', function (RaceOutcome $outcome): void {
    $race = $this->service->record($this->user, $this->race, $outcome);

    expect($race->outcome)->toBe($outcome)
        ->and($race->finish_time_sec)->toBeNull()
        ->and($race->outcome_activity_id)->toBeNull()
        ->and(PerformanceEvidence::query()->count())->toBe(0);
})->with([RaceOutcome::DidNotRun, RaceOutcome::Cancelled]);

it('confirms a manual finish time against the race distance, then lets the athlete correct any state later, recording each change and retracting stale evidence', function (): void {
    $race = $this->service->record($this->user, $this->race, RaceOutcome::Confirmed, finishTimeSec: 3_100);

    $evidence = PerformanceEvidence::query()->sole();
    expect($race->finish_time_sec)->toBe(3_100)
        ->and($race->outcome_activity_id)->toBeNull()
        ->and($evidence->distance_m)->toBe(10_000)
        ->and($evidence->elapsed_time_sec)->toBe(3_100)
        ->and($evidence->activity_id)->toBeNull();

    $this->service->record($this->user, $this->race->fresh(), RaceOutcome::Confirmed, finishTimeSec: 3_050);
    expect(PerformanceEvidence::query()->sole()->elapsed_time_sec)->toBe(3_050);

    $this->service->record($this->user, $this->race->fresh(), RaceOutcome::DidNotRun);
    expect(PerformanceEvidence::query()->count())->toBe(0)
        ->and($this->race->fresh()->finish_time_sec)->toBeNull();

    $reopened = $this->service->record($this->user, $this->race->fresh(), RaceOutcome::Pending);

    expect($reopened->outcome)->toBe(RaceOutcome::Pending)
        ->and($reopened->changes->pluck('outcome')->all())->toBe([RaceOutcome::Confirmed, RaceOutcome::Confirmed, RaceOutcome::DidNotRun, RaceOutcome::Pending]);
});

it('accepts a late confirmation long after the date', function (): void {
    Carbon::setTestNow('2026-12-20 09:00:00');

    $race = $this->service->record($this->user, $this->race, RaceOutcome::Confirmed, finishTimeSec: 3_000);

    expect($race->outcome)->toBe(RaceOutcome::Confirmed);
});

it('does not take an outcome for a race that has not happened yet', function (): void {
    $future = RaceGoal::factory()->for($this->user)->create(['race_date' => '2026-10-20', 'outcome' => RaceOutcome::Pending]);

    expect(fn () => $this->service->record($this->user, $future, RaceOutcome::DidNotRun))->toThrow(ValidationException::class);
    expect($future->fresh()->outcome)->toBe(RaceOutcome::Pending);
});

it('retires an active race whose passed date is confirmed before the nightly close', function (): void {
    $active = RaceGoal::factory()->for($this->user)->create(['race_date' => '2026-10-05', 'outcome' => RaceOutcome::Pending]);

    $race = $this->service->record($this->user, $active, RaceOutcome::Confirmed, finishTimeSec: 3_000);

    expect($race->completed_at)->not->toBeNull();
});

it('keeps a result beyond the evidence range on the race without feeding fitness', function (): void {
    $ultra = RaceGoal::factory()->for($this->user)->completed()->create([
        'race_date' => '2026-10-04', 'distance_m' => 80_000, 'goal_time_sec' => 36_000, 'outcome' => RaceOutcome::Pending,
    ]);

    $race = $this->service->record($this->user, $ultra, RaceOutcome::Confirmed, finishTimeSec: 40_000);

    expect($race->finish_time_sec)->toBe(40_000)
        ->and(PerformanceEvidence::query()->count())->toBe(0);
});

it('feeds a rounded 42.2 km marathon to fitness at the marathon distance', function (): void {
    $marathon = RaceGoal::factory()->for($this->user)->completed()->create([
        'race_date' => '2026-10-04', 'distance_m' => 42_200, 'goal_time_sec' => 13_000, 'outcome' => RaceOutcome::Pending,
    ]);

    $race = $this->service->record($this->user, $marathon, RaceOutcome::Confirmed, finishTimeSec: 13_200);

    expect($race->finish_time_sec)->toBe(13_200)
        ->and(PerformanceEvidence::query()->sole()->distance_m)->toBe(42_195);
});

it('refuses another athlete\'s race', function (): void {
    $intruder = User::factory()->create();

    expect(fn () => $this->service->record($intruder, $this->race, RaceOutcome::DidNotRun))->toThrow(AuthorizationException::class);
    expect($this->race->fresh()->outcome)->toBe(RaceOutcome::Pending);
});

it('carries a late confirmation into the performance of the season that was already settled', function (): void {
    $season = Season::factory()->for($this->user)->create(['race_goal_id' => $this->race->id, 'starts_at' => '2026-09-01', 'ends_at' => '2026-10-04']);
    app(SeasonRecordBuilder::class)->settle($this->user, $season->load('goals', 'raceGoal'), Carbon::today());
    expect($season->fresh()->performance_state)->toBe(SeasonPerformance::Pending);

    $this->service->record($this->user, $this->race, RaceOutcome::Confirmed, finishTimeSec: 3_100);
    expect($season->fresh()->performance_state)->toBe(SeasonPerformance::Met);

    $this->service->record($this->user, $this->race->fresh(), RaceOutcome::DidNotRun);
    expect($season->fresh()->performance_state)->toBe(SeasonPerformance::DidNotRun);
});
