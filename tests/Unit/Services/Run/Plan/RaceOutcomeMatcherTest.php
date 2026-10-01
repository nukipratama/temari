<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Plan\RaceOutcomeMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->matcher = app(RaceOutcomeMatcher::class);
    $this->user = User::factory()->create();
    $this->race = RaceGoal::factory()->for($this->user)->create(['race_date' => '2026-10-04', 'distance_m' => 10_000]);
});

function runOn(User $user, string $startsAt, float $distance, int $elapsed = 3_000): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => $startsAt, 'distance' => $distance, 'elapsed_time' => $elapsed, 'moving_time' => $elapsed]);

    return $activity;
}

it('suggests the owned race-day run closest to the race distance', function (): void {
    runOn($this->user, '2026-10-04 06:00:00', 9_300.0);
    $closest = runOn($this->user, '2026-10-04 07:00:00', 10_040.0);
    runOn($this->user, '2026-10-04 18:00:00', 10_900.0);

    expect($this->matcher->suggest($this->race)['activity_id'])->toBe($closest->id)
        ->and($this->matcher->candidates($this->race))->toHaveCount(3);
});

it('breaks a distance tie with the longer run', function (): void {
    runOn($this->user, '2026-10-04 06:00:00', 9_800.0);
    $longer = runOn($this->user, '2026-10-04 08:00:00', 10_200.0);

    expect($this->matcher->suggest($this->race)['activity_id'])->toBe($longer->id);
});

it('offers nothing for a warm-up, another day, another athlete or an unfinished ingest', function (): void {
    runOn($this->user, '2026-10-04 05:30:00', 2_000.0);
    runOn($this->user, '2026-10-03 07:00:00', 10_000.0);
    runOn($this->user, '2026-10-05 07:00:00', 10_000.0);
    runOn(User::factory()->create(), '2026-10-04 07:00:00', 10_000.0);
    $stub = Activity::factory()->for($this->user)->create(['analyzed_at' => null]);
    ActivityDetail::factory()->for($stub)->create(['start_date_local' => '2026-10-04 07:00:00', 'distance' => 10_000.0]);

    expect($this->matcher->suggest($this->race))->toBeNull();
});

it('shapes a candidate with the figures the athlete confirms', function (): void {
    $run = runOn($this->user, '2026-10-04 07:00:00', 10_040.0, 2_950);

    expect($this->matcher->suggest($this->race))->toBe([
        'activity_id' => $run->id,
        'name' => $run->detail->name,
        'distance_m' => 10_040.0,
        'elapsed_time_sec' => 2_950,
        'started_at' => '2026-10-04 07:00:00',
    ]);
});
