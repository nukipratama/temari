<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\LifetimeStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('aggregates run count, total km and the first-run timestamp', function (): void {
    $user = User::factory()->create();

    foreach ([['2026-01-10', 5_000], ['2026-02-20', 8_000]] as [$date, $dist]) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::parse($date.' 07:00:00'),
            'distance' => $dist,
        ]);
    }

    $stats = new LifetimeStats()->forUser($user);

    expect($stats['total_runs'])->toBe(2)
        ->and($stats['total_km'])->toBe(13.0)
        ->and($stats['longest_km'])->toBe(8.0)
        ->and($stats['first_run_at'])->not->toBeNull();
});

it('counts only analyzed runs belonging to the user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $pending = Activity::factory()->for($user)->create(['analyzed_at' => null]);
    ActivityDetail::factory()->for($pending)->create(['distance' => 9_000]);

    $otherActivity = Activity::factory()->for($other)->analyzed()->create();
    ActivityDetail::factory()->for($otherActivity)->create(['distance' => 9_000]);

    $stats = new LifetimeStats()->forUser($user);

    expect($stats['total_runs'])->toBe(0)
        ->and($stats['total_km'])->toBe(0.0)
        ->and($stats['first_run_at'])->toBeNull();
});

it('caches the aggregate under a per-user key', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['distance' => 5_000]);

    $service = new LifetimeStats();
    $first = $service->forUser($user);

    expect(Cache::has(LifetimeStats::cacheKey($user->id)))->toBeTrue();

    // A second run added after the first call must NOT show up until the cache
    // expires: the cached payload is returned verbatim.
    $second = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($second)->create(['distance' => 7_000]);

    expect($service->forUser($user))->toBe($first)
        ->and($service->forUser($user)['total_runs'])->toBe(1);
});
