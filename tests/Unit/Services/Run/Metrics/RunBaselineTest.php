<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\RunBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function baselineRun(User $user, Carbon $when, float $distance, int $movingTime, ?float $hr, ?float $decoupling): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $when,
        'distance' => $distance,
        'moving_time' => $movingTime,
        'average_heartrate' => $hr,
        'stream_summary' => $decoupling === null ? null : ['decoupling_pct' => $decoupling],
    ]);

    return $activity;
}

$asOf = fn (): Carbon => Carbon::parse('2026-06-15 08:00:00');

it('returns null when there are no prior runs in the window', function () use ($asOf): void {
    $user = User::factory()->create();

    expect((new RunBaseline())->forUserAsOf($user->id, $asOf()))->toBeNull();
});

it('aggregates distance-weighted pace, mean HR, and mean decoupling over the window', function () use ($asOf): void {
    $user = User::factory()->create();
    baselineRun($user, $asOf()->copy()->subDays(10), 10000.0, 3600, 150.0, 6.0);
    baselineRun($user, $asOf()->copy()->subDays(20), 5000.0, 1500, 160.0, 8.0);

    expect((new RunBaseline())->forUserAsOf($user->id, $asOf()))->toMatchArray([
        'runs' => 2,
        'avg_pace_sec_per_km' => 340, // (3600 + 1500) / 15 km
        'avg_hr' => 155,
        'avg_decoupling_pct' => 7.0,
    ]);
});

it('excludes the current activity and runs outside the 28-day window', function () use ($asOf): void {
    $user = User::factory()->create();
    $current = baselineRun($user, $asOf()->copy()->subDay(), 8000.0, 2400, 158.0, 5.0); // dropped by exclude id
    baselineRun($user, $asOf()->copy()->subDays(40), 12000.0, 4800, 145.0, 4.0);        // out of window
    baselineRun($user, $asOf()->copy()->subDays(3), 6000.0, 1800, 150.0, 6.0);          // the only one that counts

    $result = (new RunBaseline())->forUserAsOf($user->id, $asOf(), $current->id);

    expect($result['runs'])->toBe(1)
        ->and($result['avg_pace_sec_per_km'])->toBe(300) // 1800 / 6 km
        ->and($result['avg_hr'])->toBe(150);
});

it('counts runs but nulls metrics that have no data', function () use ($asOf): void {
    $user = User::factory()->create();
    baselineRun($user, $asOf()->copy()->subDays(5), 5000.0, 1500, null, null);

    $result = (new RunBaseline())->forUserAsOf($user->id, $asOf());

    expect($result['runs'])->toBe(1)
        ->and($result['avg_pace_sec_per_km'])->toBe(300)
        ->and($result['avg_hr'])->toBeNull()
        ->and($result['avg_decoupling_pct'])->toBeNull();
});

it('scopes the baseline to the given user', function () use ($asOf): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    baselineRun($other, $asOf()->copy()->subDays(5), 9000.0, 2700, 152.0, 5.0);

    expect((new RunBaseline())->forUserAsOf($user->id, $asOf()))->toBeNull();
});
