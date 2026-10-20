<?php

declare(strict_types=1);

use App\Actions\Run\Plan\ResolveTrainingPreferenceAction;
use App\Models\PlannedSession;
use App\Models\TrainingPreference;
use Illuminate\Support\Facades\Queue;
use App\Models\User;
use Illuminate\Support\Carbon;
use App\Models\RaceGoal;
use App\Services\Run\Plan\PlanRegenerateCooldown;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('regenerates the plan for every user', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->artisan('plan:regenerate')
        ->expectsOutputToContain('Regenerated the plan for 2 user(s).')
        ->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $a->id)->exists())->toBeTrue()
        ->and(PlannedSession::query()->where('user_id', $b->id)->exists())->toBeTrue();
});

it('limits to a single user via --user', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->artisan("plan:regenerate --user={$a->id}")
        ->expectsOutputToContain('Regenerated the plan for 1 user(s).')
        ->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $a->id)->exists())->toBeTrue()
        ->and(PlannedSession::query()->where('user_id', $b->id)->exists())->toBeFalse();
});

/**
 * A race whose day had passed made PhaseSchedule::forRace() count a negative
 * number of weeks and array_fill() throw, taking every athlete after the thrower
 * in the same run with it.
 */
it('regenerates a user whose race day has passed instead of throwing', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $user = User::factory()->create();
    RaceGoal::query()->create([
        'user_id' => $user->id,
        'race_date' => Carbon::today()->subDays(21)->toDateString(),
        'distance_m' => 21_097,
        'goal_time_sec' => 7_200,
        'name' => 'already run',
    ]);

    $this->artisan('plan:regenerate', ['--user' => $user->id])->assertSuccessful();

    Carbon::setTestNow();
});

it('keeps regenerating later athletes when one athlete throws, and fails the run', function (): void {
    $failing = User::factory()->create();
    $later = User::factory()->create();
    $realPreference = app(ResolveTrainingPreferenceAction::class);
    $preference = Mockery::mock(ResolveTrainingPreferenceAction::class);
    $preference->shouldReceive('__invoke')->andReturnUsing(
        static fn (int $userId): ?TrainingPreference => $userId === $failing->id
            ? throw new LogicException('test regenerate failure')
            : $realPreference($userId),
    );
    app()->instance(ResolveTrainingPreferenceAction::class, $preference);

    $this->artisan('plan:regenerate')
        ->expectsOutputToContain('Regenerated the plan for 1 user(s). 1 failed; see the log.')
        ->assertFailed();

    expect(PlannedSession::query()->where('user_id', $failing->id)->exists())->toBeFalse()
        ->and(PlannedSession::query()->where('user_id', $later->id)->exists())->toBeTrue();
});

it('regenerates the plan for a dormant athlete too', function (): void {
    Queue::fake();
    $active = User::factory()->seenToday()->create();
    $dormant = User::factory()->create(['last_seen_at' => Carbon::today()->subDays(30)]);

    $this->artisan('plan:regenerate')
        ->expectsOutputToContain('Regenerated the plan for 2 user(s).')
        ->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $dormant->id)->exists())->toBeTrue();
});

it('starts the manual regenerate cooldown only for recently active athletes', function (): void {
    Queue::fake();
    $active = User::factory()->seenToday()->create();
    $dormant = User::factory()->create(['last_seen_at' => Carbon::today()->subDays(30)]);

    $this->artisan('plan:regenerate')->assertSuccessful();

    $cooldown = new PlanRegenerateCooldown();
    expect($cooldown->remaining($active))->not->toBeNull()
        ->and($cooldown->remaining($dormant))->toBeNull();
});
