<?php

declare(strict_types=1);

use App\Enums\RaceOutcome;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PerformanceEvidence;
use App\Models\RaceGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-05 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** @return array{user: User, race: RaceGoal} */
function raceOutcomeFixture(): array
{
    $user = User::factory()->create();

    return [
        'user' => $user,
        'race' => RaceGoal::factory()->for($user)->completed()->create([
            'race_date' => '2026-10-04', 'distance_m' => 10_000, 'goal_time_sec' => 3_000, 'outcome' => RaceOutcome::Pending,
        ]),
    ];
}

function ownedRaceDayRun(User $user, string $startsAt = '2026-10-04 07:00:00', float $distance = 10_040.0): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => $startsAt, 'distance' => $distance, 'elapsed_time' => 2_950, 'moving_time' => 2_950]);

    return $activity;
}

it('offers the matched run and confirms it', function (): void {
    ['user' => $user, 'race' => $race] = raceOutcomeFixture();
    $run = ownedRaceDayRun($user);

    $this->actingAs($user)->get('/race')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $deferred) => $deferred
        ->where('past_races.0.id', $race->id)
        ->where('past_races.0.outcome.state', 'pending')
        ->where('past_races.0.outcome.suggestion.activity_id', $run->id)
        ->where('past_races.0.outcome.suggestion.elapsed_time_sec', 2_950)));

    $this->actingAs($user)
        ->post("/race/{$race->id}/outcome", ['outcome' => 'confirmed', 'activity_id' => $run->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($race->refresh()->outcome)->toBe(RaceOutcome::Confirmed)
        ->and($race->refresh()->finish_time_sec)->toBe(2_950)
        ->and(PerformanceEvidence::query()->where('race_goal_id', $race->id)->count())->toBe(1);

    $this->actingAs($user)->get('/race')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $deferred) => $deferred
        ->where('past_races.0.outcome.state', 'confirmed')
        ->where('past_races.0.outcome.suggestion', null)
        ->where('past_races.0.outcome.finish_time_sec', 2_950)));
});

it('refuses the demo account a race outcome', function (): void {
    raceOutcomeFixture();
    $demo = User::factory()->create(['is_demo' => true]);
    $race = RaceGoal::factory()->for($demo)->completed()->create([
        'race_date' => '2026-10-04', 'distance_m' => 10_000, 'goal_time_sec' => 3_000, 'outcome' => RaceOutcome::Pending,
    ]);

    $this->actingAs($demo)->postJson("/race/{$race->id}/outcome", ['outcome' => 'did_not_run'])->assertForbidden();

    expect($race->refresh()->outcome)->toBe(RaceOutcome::Pending);
});

it('never lets one athlete confirm another athlete\'s race', function (): void {
    ['race' => $race] = raceOutcomeFixture();
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->post("/race/{$race->id}/outcome", ['outcome' => 'did_not_run'])
        ->assertForbidden();

    expect($race->refresh()->outcome)->toBe(RaceOutcome::Pending);
});

it('never suggests another athlete\'s run', function (): void {
    ['user' => $user] = raceOutcomeFixture();
    ownedRaceDayRun(User::factory()->create());

    $this->actingAs($user)->get('/race')
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $deferred) => $deferred->where('past_races.0.outcome.suggestion', null)));
});

it('shows only retired races that have passed and carry an outcome', function (): void {
    ['user' => $user, 'race' => $race] = raceOutcomeFixture();
    RaceGoal::factory()->for($user)->completed()->create(['race_date' => '2026-09-01', 'outcome' => null]);
    RaceGoal::factory()->for($user)->create(['race_date' => '2026-12-01', 'outcome' => RaceOutcome::Pending]);
    RaceGoal::factory()->for($user)->completed()->create(['race_date' => '2026-11-01', 'outcome' => RaceOutcome::Cancelled]);

    $this->actingAs($user)->get('/race')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $deferred) => $deferred
        ->has('past_races', 1)
        ->where('past_races.0.id', $race->id)));
});

it('rejects a confirmation with neither a run nor a time, and an unknown outcome', function (): void {
    ['user' => $user, 'race' => $race] = raceOutcomeFixture();
    $this->actingAs($user)
        ->post("/race/{$race->id}/outcome", ['outcome' => 'confirmed'])
        ->assertSessionHasErrors('outcome');
    $this->actingAs($user)
        ->post("/race/{$race->id}/outcome", ['outcome' => 'won'])
        ->assertSessionHasErrors('outcome');
});
