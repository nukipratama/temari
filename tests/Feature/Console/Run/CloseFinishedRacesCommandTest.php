<?php

declare(strict_types=1);

use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function raceOn(User $user, string $date): RaceGoal
{
    return RaceGoal::query()->create([
        'user_id' => $user->id,
        'race_date' => $date,
        'distance_m' => 21_097,
        'goal_time_sec' => 7_200,
        'name' => 'A half',
    ]);
}

it('retires a race whose day has passed', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $race = raceOn(User::factory()->create(), Carbon::today()->subDay()->toDateString());

    $this->artisan('plan:close-finished-races')->assertSuccessful();

    expect($race->fresh()->completed_at)->not->toBeNull();
    Carbon::setTestNow();
});

/** Race day itself is still a race — the athlete may not have run yet. */
it('leaves today\'s race and future races alone', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $user = User::factory()->create();
    $today = raceOn($user, Carbon::today()->toDateString());
    $ahead = raceOn(User::factory()->create(), Carbon::today()->addMonth()->toDateString());

    $this->artisan('plan:close-finished-races')->assertSuccessful();

    expect($today->fresh()->completed_at)->toBeNull()
        ->and($ahead->fresh()->completed_at)->toBeNull();
    Carbon::setTestNow();
});

it('never reopens or re-stamps a race already closed', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $race = raceOn(User::factory()->create(), Carbon::today()->subDays(30)->toDateString());
    $race->update(['completed_at' => Carbon::today()->subDays(29)]);
    $stamped = $race->fresh()->completed_at;

    $this->artisan('plan:close-finished-races')->assertSuccessful();

    expect($race->fresh()->completed_at->equalTo($stamped))->toBeTrue();
    Carbon::setTestNow();
});

/**
 * The whole point: once retired, the periodizer stops planning against a day
 * in the past and falls back to the self-scaled arc instead of throwing.
 */
it('lets the plan regenerate again once the race is retired', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $user = User::factory()->create();
    raceOn($user, Carbon::today()->subDays(21)->toDateString());

    $this->artisan('plan:close-finished-races')->assertSuccessful();
    $this->artisan('plan:regenerate', ['--user' => $user->id])->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $user->id)->count())
        ->toBeGreaterThan(0);
    Carbon::setTestNow();
});
