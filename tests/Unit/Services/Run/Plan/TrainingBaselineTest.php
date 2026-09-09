<?php

declare(strict_types=1);

use App\Enums\ExperienceLevel;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\PhaseSchedule;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * The athlete's paces are a sibling concern, so they are stubbed rather than
 * built out of PersonalRecord rows: `null` models the common case of a runner
 * with no VDOT yet, where only the race-distance band can cap the long run.
 */
function baselineWithEasyPace(?int $easySecPerKm): TrainingBaseline
{
    $vdot = Mockery::mock(VdotEstimator::class);
    $vdot->shouldReceive('estimate')->andReturn($easySecPerKm === null ? null : ['vdot' => 45.0]);

    $paces = Mockery::mock(TrainingPaceCalculator::class);
    $paces->shouldReceive('fromVdotResult')->andReturn(
        $easySecPerKm === null
            ? null
            : ['easy' => $easySecPerKm, 'marathon' => 320, 'threshold' => 292, 'interval' => 268],
    );

    return new TrainingBaseline($vdot, $paces, new PhaseSchedule());
}

function weeksOf(User $user, array $volumesKm, int $runs = 4): void
{
    foreach ($volumesKm as $i => $km) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => $runs,
            'distance_km' => $km,
        ]);
    }
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 12:00:00');
    $this->baseline = baselineWithEasyPace(null);
});
afterEach(fn () => Carbon::setTestNow());

it('falls back to the floor of 3 sessions/week and a default volume with no history', function (): void {
    $user = User::factory()->create();

    $result = $this->baseline->forUser($user, Carbon::today());

    expect($result['sessions_per_week'])->toBe(3)
        ->and($result['weekly_volume_km'])->toBe(15.0)
        ->and($result['long_run_km'])->toBeGreaterThan(0.0);
});

it('clamps sessions_per_week to the trailing average, floored at 3 and capped at 6', function (): void {
    $user = User::factory()->create();
    weeksOf($user, [40.0, 40.0, 40.0, 40.0], runs: 7);

    expect($this->baseline->forUser($user, Carbon::today())['sessions_per_week'])->toBe(6);
});

/**
 * The whole point of the anchor: one 44.7 km week is dropped rather than
 * allowed to set every session in the plan.
 */
it('trims the highest and lowest week out of weekly_volume_km', function (): void {
    $user = User::factory()->create();
    weeksOf($user, [20.0, 22.0, 24.0, 25.0, 18.0, 44.7]);

    expect($this->baseline->forUser($user, Carbon::today())['weekly_volume_km'])->toBe(22.75);
});

it('takes the plain mean when there are too few weeks to trim', function (): void {
    $user = User::factory()->create();
    weeksOf($user, [20.0, 30.0]);

    expect($this->baseline->forUser($user, Carbon::today())['weekly_volume_km'])->toBe(25.0);
});

it('trims as soon as a third week exists', function (): void {
    $user = User::factory()->create();
    weeksOf($user, [10.0, 20.0, 90.0]);

    expect($this->baseline->forUser($user, Carbon::today())['weekly_volume_km'])->toBe(20.0);
});

it('still tracks a genuine ramp rather than lagging like a median', function (): void {
    $user = User::factory()->create();
    weeksOf($user, [50.0, 45.0, 40.0, 35.0, 30.0, 25.0]);

    expect($this->baseline->forUser($user, Carbon::today())['weekly_volume_km'])->toBe(37.5);
});

it('reads only the trailing six weeks', function (): void {
    $user = User::factory()->create();
    weeksOf($user, [20.0, 20.0, 20.0, 20.0, 20.0, 20.0, 200.0, 200.0]);

    expect($this->baseline->forUser($user, Carbon::today())['weekly_volume_km'])->toBe(20.0);
});

it('derives the long run as a share of weekly volume that rises as volume falls', function (
    float $weeklyKm,
    float $expectedLongRunKm,
): void {
    $user = User::factory()->create();
    weeksOf($user, array_fill(0, 6, $weeklyKm));

    expect($this->baseline->forUser($user, Carbon::today())['long_run_km'])->toBe($expectedLongRunKm);
})->with([
    'under 30 km/wk takes 35%' => [20.0, 7.0],
    '30-60 km/wk takes 30%' => [45.0, 13.5],
    'over 60 km/wk takes 25%' => [70.0, 17.5],
]);

it('caps the long run by race distance, the ratio inverting as the race lengthens', function (
    int $raceDistanceM,
    float $expectedCapKm,
): void {
    $user = User::factory()->create();
    weeksOf($user, array_fill(0, 6, 200.0));
    RaceGoal::factory()->for($user)->create(['distance_m' => $raceDistanceM]);

    expect($this->baseline->forUser($user, Carbon::today())['long_run_km'])->toBe($expectedCapKm);
})->with([
    '5K' => [5_000, 16.0],
    '10K' => [10_000, 20.0],
    'half' => [21_097, 22.0],
    'marathon' => [42_195, 35.0],
]);

it('floors the long run so the arc reaches the race distance at its own peak', function (): void {
    $user = User::factory()->create();
    weeksOf($user, array_fill(0, 6, 26.0));
    RaceGoal::factory()->for($user)->create(['distance_m' => 10_000, 'race_date' => '2026-10-03']);
    Season::factory()->for($user)->create([
        'anchor_weekly_volume_km' => 26.0,
        'starts_at' => '2026-08-10',
        'ends_at' => '2026-10-03',
    ]);

    // The share alone gives 26.0 x 0.35 = 9.1 km, and this eight-week arc
    // only ever ramps to 1.075 — not enough to carry 9.1 km to the race
    // distance. The floor lifts the baseline to 9.4, which the ramp then
    // takes past 10 km, rather than handing over 10 km in week one.
    expect($this->baseline->forUser($user, Carbon::today())['long_run_km'])->toBe(9.4);
});

it('leaves a marathon goal to its own coaching rather than flooring at race distance', function (): void {
    $user = User::factory()->create();
    weeksOf($user, array_fill(0, 6, 26.0));
    RaceGoal::factory()->for($user)->create(['distance_m' => 42_195, 'race_date' => '2026-10-03']);
    Season::factory()->for($user)->create([
        'anchor_weekly_volume_km' => 26.0,
        'starts_at' => '2026-08-10',
        'ends_at' => '2026-10-03',
    ]);

    expect($this->baseline->forUser($user, Carbon::today())['long_run_km'])->toBe(9.1);
});

it('never lets the floor take more than half the week', function (): void {
    $user = User::factory()->create();
    weeksOf($user, array_fill(0, 6, 12.0));
    RaceGoal::factory()->for($user)->create(['distance_m' => 10_000, 'race_date' => '2026-10-05']);
    Season::factory()->for($user)->create([
        'anchor_weekly_volume_km' => 12.0,
        'starts_at' => '2026-08-10',
        'ends_at' => '2026-10-05',
    ]);

    expect($this->baseline->forUser($user, Carbon::today())['long_run_km'])->toBe(6.0);
});

it('applies no race-distance floor without a season to place the week in', function (): void {
    $user = User::factory()->create();
    weeksOf($user, array_fill(0, 6, 26.0));
    RaceGoal::factory()->for($user)->create(['distance_m' => 10_000, 'race_date' => '2026-10-05']);

    expect($this->baseline->forUser($user, Carbon::today())['long_run_km'])->toBe(9.1);
});

it('falls back to the half-marathon cap when no race is set', function (): void {
    $user = User::factory()->create();
    weeksOf($user, array_fill(0, 6, 200.0));

    expect($this->baseline->forUser($user, Carbon::today())['long_run_km'])->toBe(22.0);
});

it('caps the long run at 2.5 hours of easy running when a VDOT exists', function (): void {
    $user = User::factory()->create();
    weeksOf($user, array_fill(0, 6, 200.0));

    // 7:30/km easy → 150 min buys 20 km, tighter than the 22 km no-race band.
    expect(baselineWithEasyPace(450)->forUser($user, Carbon::today())['long_run_km'])->toBe(20.0);
});

it('lets the race band bind when it is tighter than the time cap', function (): void {
    $user = User::factory()->create();
    weeksOf($user, array_fill(0, 6, 200.0));
    RaceGoal::factory()->for($user)->create(['distance_m' => 5_000]);

    // 5:00/km easy buys 30 km on time, so the 5K band decides instead.
    expect(baselineWithEasyPace(300)->forUser($user, Carbon::today())['long_run_km'])->toBe(16.0);
});

it('skips the time cap entirely for an athlete with no VDOT', function (): void {
    $user = User::factory()->create();
    weeksOf($user, array_fill(0, 6, 40.0));

    expect($this->baseline->forUser($user, Carbon::today())['long_run_km'])->toBe(12.0);
});

it('never prescribes a long run below the floor', function (): void {
    $user = User::factory()->create();
    weeksOf($user, array_fill(0, 6, 5.0));

    expect($this->baseline->forUser($user, Carbon::today())['long_run_km'])->toBe(3.0);
});

/**
 * The reported regression: a runner averaging ~22 km/week was prescribed a
 * 24 km long run — a one-off event — which scaled the whole week to ~59 km.
 */
it('anchors a real 22 km/week runner near 8 km, not on their one-off 24 km run', function (): void {
    $user = User::factory()->create();
    weeksOf($user, [20.0, 22.0, 24.0, 25.0, 18.0, 44.7]);
    RaceGoal::factory()->for($user)->create(['distance_m' => 10_000]);

    $result = $this->baseline->forUser($user, Carbon::today());

    expect($result['weekly_volume_km'])->toBe(22.75)
        ->and($result['long_run_km'])->toBe(8.0);
});

it('an explicit sessions_per_week preference overrides the behavioral average', function (): void {
    $user = User::factory()->create();
    weeksOf($user, [40.0], runs: 6);
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 2]);

    expect($this->baseline->forUser($user, Carbon::today())['sessions_per_week'])->toBe(2);
});

it('an explicit sessions_per_week preference bypasses the behavioral floor of 3 with zero history', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 2]);

    expect($this->baseline->forUser($user, Carbon::today())['sessions_per_week'])->toBe(2);
});

it('seeds cold-start defaults from experience_level with zero history', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create([
        'experience_level' => ExperienceLevel::NewToRunning,
        'sessions_per_week' => null,
    ]);

    $result = $this->baseline->forUser($user, Carbon::today());

    expect($result['sessions_per_week'])->toBe(3)
        ->and($result['weekly_volume_km'])->toBe(8.0);
});

it('seeds an experienced athlete conservatively rather than on their say-so', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create([
        'experience_level' => ExperienceLevel::Experienced,
        'sessions_per_week' => null,
    ]);

    expect($this->baseline->forUser($user, Carbon::today())['weekly_volume_km'])->toBe(22.0);
});

it('real logged behavior wins over an experience_level seed once any history exists', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create([
        'experience_level' => ExperienceLevel::Experienced,
        'sessions_per_week' => null,
    ]);
    weeksOf($user, [22.0]);

    expect($this->baseline->forUser($user, Carbon::today())['weekly_volume_km'])->toBe(22.0);
});
