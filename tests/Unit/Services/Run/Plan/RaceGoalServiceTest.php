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
    $this->service = app(RaceGoalService::class);
    $this->seasons = app(SeasonService::class);
    $this->user = User::factory()->create();
});
afterEach(fn () => Carbon::setTestNow());

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
    $race = $this->service->submit($this->user, submission(), RaceIntent::Update);

    expect($race->outcome)->toBe(RaceOutcome::Pending)
        ->and($race->completed_at)->toBeNull()
        ->and($race->changes)->toHaveCount(1)
        ->and($race->changes->first()->kind)->toBe(RaceChangeKind::Created)
        ->and($race->changes->first()->goal_time_sec)->toBe(3_000);
});

it('keeps the event and its season when only the target time is revised', function (): void {
    $race = $this->service->submit($this->user, submission(), RaceIntent::Update);
    $season = $this->seasons->ensureCurrent($this->user, Carbon::today());

    $revised = $this->service->submit($this->user, submission(['goal_time_sec' => 2_900]), RaceIntent::Update);

    expect($revised->id)->toBe($race->id)
        ->and($revised->goal_time_sec)->toBe(2_900)
        ->and($revised->changes->pluck('kind')->all())->toBe([RaceChangeKind::Created, RaceChangeKind::Revised])
        ->and($revised->changes->pluck('goal_time_sec')->all())->toBe([3_000, 2_900])
        ->and($this->seasons->ensureCurrent($this->user, Carbon::today())->id)->toBe($season->id);
});

it('treats a date change as a postponement that keeps the season and the old date on record', function (): void {
    $race = $this->service->submit($this->user, submission(), RaceIntent::Update);
    $season = $this->seasons->ensureCurrent($this->user, Carbon::today());
    $oldDate = $race->race_date->toDateString();
    $newDate = Carbon::today()->addWeeks(14)->toDateString();

    $moved = $this->service->submit($this->user, submission(['race_date' => $newDate]), RaceIntent::Update);
    $sameSeason = $this->seasons->ensureCurrent($this->user, Carbon::today());

    expect($moved->id)->toBe($race->id)
        ->and($moved->changes->pluck('kind')->all())->toBe([RaceChangeKind::Created, RaceChangeKind::Postponed])
        ->and($moved->changes->map(fn ($change) => $change->race_date->toDateString())->all())->toBe([$oldDate, $newDate])
        ->and($sameSeason->id)->toBe($season->id)
        ->and($sameSeason->ends_at->toDateString())->toBe($newDate);
});

it('changes nothing when the same revision is submitted twice', function (): void {
    $this->service->submit($this->user, submission(), RaceIntent::Update);
    $revised = $this->service->submit($this->user, submission(['goal_time_sec' => 2_900]), RaceIntent::Update);
    $again = $this->service->submit($this->user, submission(['goal_time_sec' => 2_900]), RaceIntent::Update);

    expect($again->id)->toBe($revised->id)
        ->and($again->wasChanged())->toBeFalse()
        ->and($again->changes()->count())->toBe(2);
});

it('does not record a history entry for a rename alone', function (): void {
    $this->service->submit($this->user, submission(), RaceIntent::Update);
    $renamed = $this->service->submit($this->user, submission(['name' => 'Renamed']), RaceIntent::Update);

    expect($renamed->name)->toBe('Renamed')
        ->and($renamed->changes()->count())->toBe(1);
});

it('refuses to revise an event into a different distance', function (): void {
    $this->service->submit($this->user, submission(), RaceIntent::Update);

    expect(fn () => $this->service->submit($this->user, submission(['distance_m' => 21_097]), RaceIntent::Update))
        ->toThrow(ValidationException::class);
    expect(RaceGoal::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('starts a new event and a new season on an explicit new-event intent', function (): void {
    $first = $this->service->submit($this->user, submission(), RaceIntent::Update);
    $firstSeason = $this->seasons->ensureCurrent($this->user, Carbon::today());

    Carbon::setTestNow('2026-10-06 08:00:00');
    $second = $this->service->submit($this->user, submission(['distance_m' => 21_097, 'goal_time_sec' => 6_600, 'name' => 'Half']), RaceIntent::New);
    $secondSeason = $this->seasons->ensureCurrent($this->user, Carbon::today());

    expect($second->id)->not->toBe($first->id)
        ->and($first->fresh()->completed_at)->not->toBeNull()
        ->and($first->fresh()->outcome)->toBe(RaceOutcome::Cancelled)
        ->and($first->fresh()->changes->pluck('kind')->last())->toBe(RaceChangeKind::Replaced)
        ->and($second->outcome)->toBe(RaceOutcome::Pending)
        ->and($secondSeason->id)->not->toBe($firstSeason->id)
        ->and($secondSeason->race_goal_id)->toBe($second->id)
        ->and(RaceGoal::query()->where('user_id', $this->user->id)->active()->count())->toBe(1);
});

it('does not start a second event when the same new-event submission repeats', function (): void {
    $this->service->submit($this->user, submission(), RaceIntent::Update);
    $second = $this->service->submit($this->user, submission(['name' => 'Other', 'goal_time_sec' => 2_950]), RaceIntent::New);
    $repeat = $this->service->submit($this->user, submission(['name' => 'Other', 'goal_time_sec' => 2_950]), RaceIntent::New);

    expect($repeat->id)->toBe($second->id)
        ->and(RaceGoal::query()->where('user_id', $this->user->id)->count())->toBe(2);
});

it('keeps a passed unconfirmed event pending when a new event replaces it', function (): void {
    $passed = RaceGoal::factory()->for($this->user)->create([
        'race_date' => Carbon::today()->subDay()->toDateString(),
        'outcome' => RaceOutcome::Pending,
    ]);

    $this->service->submit($this->user, submission(), RaceIntent::New);

    expect($passed->fresh()->outcome)->toBe(RaceOutcome::Pending)
        ->and($passed->fresh()->completed_at)->not->toBeNull();
});

it('cancels the active event once and records it', function (): void {
    $race = $this->service->submit($this->user, submission(), RaceIntent::Update);

    $cancelled = $this->service->cancel($this->user);
    $again = $this->service->cancel($this->user);

    expect($cancelled?->id)->toBe($race->id)
        ->and($again)->toBeNull()
        ->and($race->fresh()->outcome)->toBe(RaceOutcome::Cancelled)
        ->and($race->fresh()->completed_at)->not->toBeNull()
        ->and($race->fresh()->changes->pluck('kind')->all())->toBe([RaceChangeKind::Created, RaceChangeKind::Cancelled]);
});

it('creates for onboarding only when no event is active', function (): void {
    $first = $this->service->createUnlessActive($this->user, submission());
    $second = $this->service->createUnlessActive($this->user, submission(['name' => 'Ignored']));

    expect($second->id)->toBe($first->id)
        ->and($second->name)->toBe('Jakarta 10K')
        ->and(RaceGoal::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('never touches another athlete\'s event', function (): void {
    $other = RaceGoal::factory()->create(['outcome' => RaceOutcome::Pending]);

    $this->service->submit($this->user, submission(), RaceIntent::New);
    $this->service->cancel($this->user);

    expect($other->fresh()->completed_at)->toBeNull()
        ->and($other->fresh()->outcome)->toBe(RaceOutcome::Pending);
});
