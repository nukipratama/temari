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

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 09:00:00');
    $this->user = User::factory()->create();
    $this->race = RaceGoal::factory()->for($this->user)->completed()->create([
        'race_date' => '2026-10-04', 'distance_m' => 10_000, 'goal_time_sec' => 3_000, 'outcome' => RaceOutcome::Pending,
    ]);
});
afterEach(fn () => Carbon::setTestNow());

function ownedRaceDayRun(User $user, string $startsAt = '2026-10-04 07:00:00', float $distance = 10_040.0): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => $startsAt, 'distance' => $distance, 'elapsed_time' => 2_950, 'moving_time' => 2_950]);

    return $activity;
}

it('requires authentication', function (): void {
    $this->post("/race/{$this->race->id}/outcome", ['outcome' => 'did_not_run'])->assertRedirect('/login');
});

it('offers the matched run and confirms it', function (): void {
    $run = ownedRaceDayRun($this->user);

    $this->actingAs($this->user)->get('/race')->assertInertia(fn (Assert $page) => $page
        ->where('past_races.0.id', $this->race->id)
        ->where('past_races.0.outcome.state', 'pending')
        ->where('past_races.0.outcome.suggestion.activity_id', $run->id)
        ->where('past_races.0.outcome.suggestion.elapsed_time_sec', 2_950));

    $this->actingAs($this->user)
        ->post("/race/{$this->race->id}/outcome", ['outcome' => 'confirmed', 'activity_id' => $run->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->race->fresh()->outcome)->toBe(RaceOutcome::Confirmed)
        ->and($this->race->fresh()->finish_time_sec)->toBe(2_950)
        ->and(PerformanceEvidence::query()->where('race_goal_id', $this->race->id)->count())->toBe(1);

    $this->actingAs($this->user)->get('/race')->assertInertia(fn (Assert $page) => $page
        ->where('past_races.0.outcome.state', 'confirmed')
        ->where('past_races.0.outcome.suggestion', null)
        ->where('past_races.0.outcome.finish_time_sec', 2_950));
});

it('confirms a manual time and marks did not run', function (): void {
    $this->actingAs($this->user)
        ->post("/race/{$this->race->id}/outcome", ['outcome' => 'confirmed', 'finish_time_sec' => 3_100])
        ->assertSessionHasNoErrors();
    expect($this->race->fresh()->finish_time_sec)->toBe(3_100);

    $this->actingAs($this->user)
        ->post("/race/{$this->race->id}/outcome", ['outcome' => 'did_not_run'])
        ->assertSessionHasNoErrors();
    expect($this->race->fresh()->outcome)->toBe(RaceOutcome::DidNotRun)
        ->and(PerformanceEvidence::query()->count())->toBe(0);
});

it('refuses the demo account a race outcome', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);
    $race = RaceGoal::factory()->for($demo)->completed()->create([
        'race_date' => '2026-10-04', 'distance_m' => 10_000, 'goal_time_sec' => 3_000, 'outcome' => RaceOutcome::Pending,
    ]);

    $this->actingAs($demo)->postJson("/race/{$race->id}/outcome", ['outcome' => 'did_not_run'])->assertForbidden();

    expect($race->fresh()->outcome)->toBe(RaceOutcome::Pending);
});

it('never lets one athlete confirm another athlete\'s race', function (): void {
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->post("/race/{$this->race->id}/outcome", ['outcome' => 'did_not_run'])
        ->assertForbidden();

    expect($this->race->fresh()->outcome)->toBe(RaceOutcome::Pending);
});

it('never matches another athlete\'s activity to a race', function (): void {
    $theirs = ownedRaceDayRun(User::factory()->create());

    $this->actingAs($this->user)
        ->post("/race/{$this->race->id}/outcome", ['outcome' => 'confirmed', 'activity_id' => $theirs->id])
        ->assertSessionHasErrors('activity_id');

    expect($this->race->fresh()->outcome)->toBe(RaceOutcome::Pending);
});

it('never suggests another athlete\'s run', function (): void {
    ownedRaceDayRun(User::factory()->create());

    $this->actingAs($this->user)->get('/race')
        ->assertInertia(fn (Assert $page) => $page->where('past_races.0.outcome.suggestion', null));
});

it('rejects a confirmation with neither a run nor a time, and an unknown outcome', function (): void {
    $this->actingAs($this->user)
        ->post("/race/{$this->race->id}/outcome", ['outcome' => 'confirmed'])
        ->assertSessionHasErrors('outcome');
    $this->actingAs($this->user)
        ->post("/race/{$this->race->id}/outcome", ['outcome' => 'won'])
        ->assertSessionHasErrors('outcome');
});

it('shows only retired races that have passed and carry an outcome', function (): void {
    RaceGoal::factory()->for($this->user)->completed()->create(['race_date' => '2026-09-01', 'outcome' => null]);
    RaceGoal::factory()->for($this->user)->create(['race_date' => '2026-12-01', 'outcome' => RaceOutcome::Pending]);
    RaceGoal::factory()->for($this->user)->completed()->create(['race_date' => '2026-11-01', 'outcome' => RaceOutcome::Cancelled]);

    $this->actingAs($this->user)->get('/race')->assertInertia(fn (Assert $page) => $page
        ->has('past_races', 1)
        ->where('past_races.0.id', $this->race->id));
});
