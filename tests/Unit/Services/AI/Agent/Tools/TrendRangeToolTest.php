<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use App\Services\AI\Agent\Tools\TrendRangeTool;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');
    $this->trainingLoad = new TrainingLoad();
});
afterEach(fn () => Carbon::setTestNow());

function seedTrendRun(User $user, int $daysAgo, float $trimp, float $distanceM = 5000.0): void
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'trimp_edwards' => $trimp,
        'distance' => $distanceM,
        'start_date_local' => Carbon::today()->subDays($daysAgo),
    ]);
}

it('reports the requested range on its own reading', function (): void {
    $user = User::factory()->create();

    $context = new TrendRangeTool($user, '90d', $this->trainingLoad)->handle([]);

    expect($context['range'])->toBe('90d');
});

it('splits 30d into current (last 30 days) vs comparison (the 30 days before that)', function (): void {
    $user = User::factory()->create();
    seedTrendRun($user, 5, 100.0, 5000.0);   // inside current
    seedTrendRun($user, 45, 80.0, 4000.0);   // inside comparison

    $context = new TrendRangeTool($user, '30d', $this->trainingLoad)->handle([]);

    expect($context['current']['runs'])->toBe(1)
        ->and($context['current']['distance_km'])->toBe(5.0)
        ->and($context['current']['trimp_total'])->toBe(100.0)
        ->and($context['comparison']['runs'])->toBe(1)
        ->and($context['comparison']['distance_km'])->toBe(4.0)
        ->and($context['comparison']['trimp_total'])->toBe(80.0);
});

it('splits 12mo into second half (current) vs first half (comparison) of the same window, not a prior year', function (): void {
    $user = User::factory()->create();
    seedTrendRun($user, 30, 100.0);   // inside the second half (current)
    seedTrendRun($user, 300, 80.0);  // inside the first half (comparison)

    $context = new TrendRangeTool($user, '12mo', $this->trainingLoad)->handle([]);

    expect($context['current']['runs'])->toBe(1)
        ->and($context['current']['trimp_total'])->toBe(100.0)
        ->and($context['comparison']['runs'])->toBe(1)
        ->and($context['comparison']['trimp_total'])->toBe(80.0);
});

it('reads ctl_start/ctl_end from the same rolled series ctlTrend already exposes', function (): void {
    $user = User::factory()->create();
    for ($i = 0; $i < 60; $i++) {
        seedTrendRun($user, 59 - $i, 80.0);
    }

    $context = new TrendRangeTool($user, '30d', $this->trainingLoad)->handle([]);
    $ctlSeries = $this->trainingLoad->ctlTrend($user, 30);

    expect($context['ctl_start'])->toBe($ctlSeries[0]['ctl'])
        ->and($context['ctl_end'])->toBe(array_last($ctlSeries)['ctl']);
});

it('returns null vdot_start/vdot_end when there is no snapshot history yet', function (): void {
    $user = User::factory()->create();

    $context = new TrendRangeTool($user, '30d', $this->trainingLoad)->handle([]);

    expect($context['vdot_start'])->toBeNull()
        ->and($context['vdot_end'])->toBeNull();
});

it('reads real vdot_start/vdot_end from TrendDailySnapshot rows', function (): void {
    $user = User::factory()->create();
    TrendDailySnapshot::factory()->for($user)->create(['snapshot_date' => Carbon::today()->subDays(29), 'vdot' => 42.0]);
    TrendDailySnapshot::factory()->for($user)->create(['snapshot_date' => Carbon::today(), 'vdot' => 45.5]);

    $context = new TrendRangeTool($user, '30d', $this->trainingLoad)->handle([]);

    expect($context['vdot_start'])->toBe(42.0)
        ->and($context['vdot_end'])->toBe(45.5);
});

it('averages monotony/strain across the current period only, ignoring nulls', function (): void {
    $user = User::factory()->create();
    for ($i = 0; $i < 14; $i++) {
        seedTrendRun($user, 13 - $i, 80.0);
    }

    $context = new TrendRangeTool($user, '30d', $this->trainingLoad)->handle([]);

    expect($context['avg_monotony'])->toBeFloat()
        ->and($context['avg_strain'])->toBeFloat();
});

it('returns null averages for a user with no history at all', function (): void {
    $user = User::factory()->create();

    $context = new TrendRangeTool($user, '30d', $this->trainingLoad)->handle([]);

    expect($context['avg_monotony'])->toBeNull()
        ->and($context['avg_strain'])->toBeNull()
        ->and($context['current']['runs'])->toBe(0)
        ->and($context['current']['trimp_total'])->toBeNull();
});
