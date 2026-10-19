<?php

declare(strict_types=1);

use App\Enums\RaceChangeKind;
use App\Enums\RaceIntent;
use App\Enums\RaceOutcome;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Plan\RaceGoalService;
use App\Services\Run\Plan\SeasonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 08:00:00');
});
afterEach(fn () => Carbon::setTestNow());

function raceGoalService(): RaceGoalService
{
    return app(RaceGoalService::class);
}

function seasonService(): SeasonService
{
    return app(SeasonService::class);
}

/**
 * @param  array{race_date?: string, distance_m?: int, goal_time_sec?: int, name?: string|null}  $overrides
 * @return array{race_date: string, distance_m: int, goal_time_sec: int, name: string|null}
 */
function submission(array $overrides = []): array
{
    return [
        'race_date' => Carbon::today()->addWeeks(10)->toDateString(),
        'distance_m' => 10_000,
        'goal_time_sec' => 3_000,
        'name' => 'Jakarta 10K',
        ...$overrides,
    ];
}

it('creates the first event as pending with a creation entry in its history', function (): void {
    $user = User::factory()->create();
    $race = raceGoalService()->submit($user, submission(), RaceIntent::Update);
    $change = nonNull($race->changes->first());

    expect($race->outcome)->toBe(RaceOutcome::Pending)
        ->and($race->completed_at)->toBeNull()
        ->and($race->changes)->toHaveCount(1)
        ->and($change->kind)->toBe(RaceChangeKind::Created)
        ->and($change->goal_time_sec)->toBe(3_000);
});

it('keeps the event and its season when only the target time is revised', function (): void {
    $user = User::factory()->create();
    $race = raceGoalService()->submit($user, submission(), RaceIntent::Update);
    $season = seasonService()->ensureCurrent($user, Carbon::today());

    $revised = raceGoalService()->submit($user, submission(['goal_time_sec' => 2_900]), RaceIntent::Update);

    expect($revised->id)->toBe($race->id)
        ->and($revised->goal_time_sec)->toBe(2_900)
        ->and($revised->changes->pluck('kind')->all())->toBe([RaceChangeKind::Created, RaceChangeKind::Revised])
        ->and($revised->changes->pluck('goal_time_sec')->all())->toBe([3_000, 2_900])
        ->and(seasonService()->ensureCurrent($user, Carbon::today())->id)->toBe($season->id);
});

it('treats a date change as a postponement that keeps the season and the old date on record', function (): void {
    $user = User::factory()->create();
    $race = raceGoalService()->submit($user, submission(), RaceIntent::Update);
    $season = seasonService()->ensureCurrent($user, Carbon::today());
    $oldDate = $race->race_date->toDateString();
    $newDate = Carbon::today()->addWeeks(14)->toDateString();

    $moved = raceGoalService()->submit($user, submission(['race_date' => $newDate]), RaceIntent::Update);
    $sameSeason = seasonService()->ensureCurrent($user, Carbon::today());

    expect($moved->id)->toBe($race->id)
        ->and($moved->changes->pluck('kind')->all())->toBe([RaceChangeKind::Created, RaceChangeKind::Postponed])
        ->and($moved->changes->map(fn ($change) => nonNull($change->race_date)->toDateString())->all())->toBe([$oldDate, $newDate])
        ->and($sameSeason->id)->toBe($season->id)
        ->and(nonNull($sameSeason->ends_at)->toDateString())->toBe($newDate);
});

it('changes nothing when the same revision is submitted twice', function (): void {
    $user = User::factory()->create();
    raceGoalService()->submit($user, submission(), RaceIntent::Update);
    $revised = raceGoalService()->submit($user, submission(['goal_time_sec' => 2_900]), RaceIntent::Update);
    $again = raceGoalService()->submit($user, submission(['goal_time_sec' => 2_900]), RaceIntent::Update);

    expect($again->id)->toBe($revised->id)
        ->and($again->wasChanged())->toBeFalse()
        ->and($again->changes()->count())->toBe(2);
});

it('does not record a history entry for a rename alone', function (): void {
    $user = User::factory()->create();
    raceGoalService()->submit($user, submission(), RaceIntent::Update);
    $renamed = raceGoalService()->submit($user, submission(['name' => 'Renamed']), RaceIntent::Update);

    expect($renamed->name)->toBe('Renamed')
        ->and($renamed->changes()->count())->toBe(1);
});

it('refuses to revise an event into a different distance', function (): void {
    $user = User::factory()->create();
    raceGoalService()->submit($user, submission(), RaceIntent::Update);

    expect(fn () => raceGoalService()->submit($user, submission(['distance_m' => 21_097]), RaceIntent::Update))
        ->toThrow(ValidationException::class);
    expect(RaceGoal::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('starts a new event and a new season on an explicit new-event intent', function (): void {
    $user = User::factory()->create();
    $first = raceGoalService()->submit($user, submission(), RaceIntent::Update);
    $firstSeason = seasonService()->ensureCurrent($user, Carbon::today());

    Carbon::setTestNow('2026-10-06 08:00:00');
    $second = raceGoalService()->submit($user, submission(['distance_m' => 21_097, 'goal_time_sec' => 6_600, 'name' => 'Half']), RaceIntent::New);
    $secondSeason = seasonService()->ensureCurrent($user, Carbon::today());

    expect($second->id)->not->toBe($first->id)
        ->and($first->refresh()->completed_at)->not->toBeNull()
        ->and($first->refresh()->outcome)->toBe(RaceOutcome::Cancelled)
        ->and($first->refresh()->changes->pluck('kind')->last())->toBe(RaceChangeKind::Replaced)
        ->and($second->outcome)->toBe(RaceOutcome::Pending)
        ->and($secondSeason->id)->not->toBe($firstSeason->id)
        ->and($secondSeason->race_goal_id)->toBe($second->id)
        ->and(RaceGoal::query()->where('user_id', $user->id)->active()->count())->toBe(1);
});

it('does not start a second event when the same new-event submission repeats', function (): void {
    $user = User::factory()->create();
    raceGoalService()->submit($user, submission(), RaceIntent::Update);
    $second = raceGoalService()->submit($user, submission(['name' => 'Other', 'goal_time_sec' => 2_950]), RaceIntent::New);
    $repeat = raceGoalService()->submit($user, submission(['name' => 'Other', 'goal_time_sec' => 2_950]), RaceIntent::New);

    expect($repeat->id)->toBe($second->id)
        ->and(RaceGoal::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('keeps a passed unconfirmed event pending when a new event replaces it', function (): void {
    $user = User::factory()->create();
    $passed = RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->subDay()->toDateString(),
        'outcome' => RaceOutcome::Pending,
    ]);

    raceGoalService()->submit($user, submission(), RaceIntent::New);

    expect($passed->refresh()->outcome)->toBe(RaceOutcome::Pending)
        ->and($passed->refresh()->completed_at)->not->toBeNull();
});

it('cancels the active event once and records it', function (): void {
    $user = User::factory()->create();
    $race = raceGoalService()->submit($user, submission(), RaceIntent::Update);

    $cancelled = raceGoalService()->cancel($user);
    $again = raceGoalService()->cancel($user);

    expect($cancelled?->id)->toBe($race->id)
        ->and($again)->toBeNull()
        ->and($race->refresh()->outcome)->toBe(RaceOutcome::Cancelled)
        ->and($race->refresh()->completed_at)->not->toBeNull()
        ->and($race->refresh()->changes->pluck('kind')->all())->toBe([RaceChangeKind::Created, RaceChangeKind::Cancelled]);
});

it('creates for onboarding only when no event is active', function (): void {
    $user = User::factory()->create();
    $first = raceGoalService()->createUnlessActive($user, submission());
    $second = raceGoalService()->createUnlessActive($user, submission(['name' => 'Ignored']));

    expect($second->id)->toBe($first->id)
        ->and($second->name)->toBe('Jakarta 10K')
        ->and(RaceGoal::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('never touches another athlete\'s event', function (): void {
    $user = User::factory()->create();
    $other = RaceGoal::factory()->create(['outcome' => RaceOutcome::Pending]);

    raceGoalService()->submit($user, submission(), RaceIntent::New);
    raceGoalService()->cancel($user);

    expect($other->refresh()->completed_at)->toBeNull()
        ->and($other->refresh()->outcome)->toBe(RaceOutcome::Pending);
});
