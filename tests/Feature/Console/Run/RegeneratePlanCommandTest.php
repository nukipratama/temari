<?php

declare(strict_types=1);

use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use App\Models\RaceGoal;
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
 * The crash this branch exists to stop: a race whose day had passed made
 * PhaseSchedule::forRace() count a negative number of weeks and array_fill()
 * throw. Because the command looped without a guard, that took every athlete
 * after the thrower in the same run with it — proven with a bystander before
 * fixing.
 *
 * The per-user try/catch that now contains such a failure has no test of its
 * own: every class in the plan chain is `final`, so no throw can be injected,
 * and no reachable data corruption I could construct still makes regenerate
 * throw. That robustness is why. The guard below covers the one cause that
 * was real.
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
