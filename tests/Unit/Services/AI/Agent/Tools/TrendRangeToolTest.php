<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use App\Services\AI\Agent\Tools\TrendRangeTool;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');
});

function trendRangeTrainingLoad(): TrainingLoad
{
    return new TrainingLoad();
}

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

function seedUnanalyzedTrendRun(User $user, int $daysAgo, float $trimp, float $distanceM = 5000.0): void
{
    $activity = Activity::factory()->for($user)->stub()->create();
    ActivityDetail::factory()->for($activity)->create([
        'trimp_edwards' => $trimp,
        'distance' => $distanceM,
        'start_date_local' => Carbon::today()->subDays($daysAgo),
    ]);
}

it('reports the requested range on its own reading', function (): void {
    $user = User::factory()->create();

    $context = new TrendRangeTool($user, '6w', trendRangeTrainingLoad())->handle([]);

    expect($context['range'])->toBe('6w');
});

it('returns null vdot_start/vdot_end when there is no snapshot history yet', function (): void {
    $user = User::factory()->create();

    $context = new TrendRangeTool($user, '6w', trendRangeTrainingLoad())->handle([]);

    expect($context['vdot_start'])->toBeNull()
        ->and($context['vdot_end'])->toBeNull();
});

it('reads real vdot_start/vdot_end from TrendDailySnapshot rows', function (): void {
    $user = User::factory()->create();
    TrendDailySnapshot::factory()->for($user)->create(['snapshot_date' => Carbon::today()->subDays(41), 'vdot' => 42.0]);
    TrendDailySnapshot::factory()->for($user)->create(['snapshot_date' => Carbon::today(), 'vdot' => 45.5]);

    $context = new TrendRangeTool($user, '6w', trendRangeTrainingLoad())->handle([]);

    expect($context['vdot_start'])->toBe(42.0)
        ->and($context['vdot_end'])->toBe(45.5);
});

it('averages monotony/strain across the current period only, ignoring nulls', function (): void {
    $user = User::factory()->create();
    for ($i = 0; $i < 14; $i++) {
        seedTrendRun($user, 13 - $i, 80.0);
    }

    $context = new TrendRangeTool($user, '6w', trendRangeTrainingLoad())->handle([]);

    expect($context['avg_monotony'])->toBeFloat()
        ->and($context['avg_strain'])->toBeFloat();
});

it('returns null averages for a user with no history at all', function (): void {
    $user = User::factory()->create();

    $context = new TrendRangeTool($user, '6w', trendRangeTrainingLoad())->handle([]);

    expect($context['avg_monotony'])->toBeNull()
        ->and($context['avg_strain'])->toBeNull()
        ->and($context['current']['runs'])->toBe(0)
        ->and($context['current']['trimp_total'])->toBeNull();
});

it('excludes a not-yet-analyzed activity from the period totals', function (): void {
    $user = User::factory()->create();
    seedTrendRun($user, 2, 100.0, 5000.0);
    seedUnanalyzedTrendRun($user, 3, 999.0, 9000.0);

    $context = new TrendRangeTool($user, '6w', trendRangeTrainingLoad())->handle([]);

    expect($context['current']['runs'])->toBe(1)
        ->and($context['current']['distance_km'])->toBe(5.0)
        ->and($context['current']['trimp_total'])->toBe(100.0);
});

it('splits 6w into the last 42 days against the 42 days before that', function (): void {
    $user = User::factory()->create();
    seedTrendRun($user, 0, 60.0, 3000.0);    // first day of current
    seedTrendRun($user, 41, 100.0, 5000.0);  // last day of current
    seedTrendRun($user, 42, 80.0, 4000.0);   // first day of comparison
    seedTrendRun($user, 83, 70.0, 7000.0);   // last day of comparison
    seedTrendRun($user, 84, 999.0, 9000.0);  // outside both

    $context = new TrendRangeTool($user, '6w', trendRangeTrainingLoad())->handle([]);

    expect($context['range'])->toBe('6w')
        ->and($context['current']['runs'])->toBe(2)
        ->and($context['current']['distance_km'])->toBe(8.0)
        ->and($context['current']['trimp_total'])->toBe(160.0)
        ->and($context['comparison']['runs'])->toBe(2)
        ->and($context['comparison']['distance_km'])->toBe(11.0)
        ->and($context['comparison']['trimp_total'])->toBe(150.0);
});

it('reads 6w ctl and strain from series spanning the 42 days', function (): void {
    $user = User::factory()->create();
    for ($i = 0; $i < 84; $i++) {
        seedTrendRun($user, 83 - $i, 80.0);
    }

    $context = new TrendRangeTool($user, '6w', trendRangeTrainingLoad())->handle([]);
    $ctlSeries = trendRangeTrainingLoad()->ctlTrend($user, 42);

    expect($ctlSeries)->toHaveCount(42)
        ->and($context['ctl_start'])->toBe($ctlSeries[0]['ctl'])
        ->and($context['ctl_end'])->toBe(nonNull(array_last($ctlSeries))['ctl'])
        ->and($context['avg_monotony'])->toBeFloat()
        ->and($context['avg_strain'])->toBeFloat();
});

it('declares the 6w window alone, apart from the one Trends read', function (): void {
    expect(TrendRangeTool::RANGE_DAYS)->toBe(['6w' => 42])
        ->and(AnalysisType::TREND_READ_RANGES)->toBe(['arc']);
});

it('reads the range as of a past date instead of today when given one', function (): void {
    $user = User::factory()->create();
    for ($i = 0; $i < 84; $i++) {
        seedTrendRun($user, 119 - $i, 80.0);
    }
    seedTrendRun($user, 10, 999.0, 9000.0);
    TrendDailySnapshot::factory()->for($user)->create(['snapshot_date' => Carbon::today()->subDays(77), 'vdot' => 41.0]);
    TrendDailySnapshot::factory()->for($user)->create(['snapshot_date' => Carbon::today()->subDays(36), 'vdot' => 43.0]);
    TrendDailySnapshot::factory()->for($user)->create(['snapshot_date' => Carbon::today(), 'vdot' => 47.0]);
    $asOf = Carbon::today()->subDays(36);

    $context = new TrendRangeTool($user, '6w', trendRangeTrainingLoad(), $asOf)->handle([]);
    $ctlSeries = trendRangeTrainingLoad()->ctlTrend($user, 42, $asOf);

    expect($context['current']['runs'])->toBe(42)
        ->and($context['current']['trimp_total'])->toBe(3360.0)
        ->and($context['comparison']['runs'])->toBe(42)
        ->and($context['ctl_start'])->toBe($ctlSeries[0]['ctl'])
        ->and($context['ctl_end'])->toBe(nonNull(array_last($ctlSeries))['ctl'])
        ->and($context['vdot_start'])->toBe(41.0)
        ->and($context['vdot_end'])->toBe(43.0);
});
