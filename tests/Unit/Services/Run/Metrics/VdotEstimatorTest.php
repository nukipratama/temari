<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\FitnessAnchor;
use App\Models\PerformanceEvidence;
use App\Models\PersonalRecord;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\FallOffExponent;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 09:00:00');
    $this->estimator = app(VdotEstimator::class);
});

afterEach(fn () => Carbon::setTestNow());

function recordRun(User $user, string $date, float $meters, int $seconds): Activity
{
    $pace = $seconds / $meters * 1000;
    $splits = [];
    for ($km = 1; $km <= (int) floor($meters / 1000); $km++) {
        $splits[] = ['km' => $km, 'pace' => PaceFormatter::format($pace), 'elapsed_sec' => $pace, 'distance_m' => 1000];
    }
    $leftover = $meters - floor($meters / 1000) * 1000;
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $date.' 06:00:00', 'distance' => $meters, 'elapsed_time' => $seconds,
        'moving_time' => $seconds, 'workout_type' => 0,
        'stream_summary' => ['per_km' => $splits, ...($leftover > 0 ? ['partial_split' => ['distance_m' => $leftover, 'pace' => PaceFormatter::format($pace)]] : [])],
    ]);

    return $activity;
}

function evenRun(User $user, string $date, float $meters, int $secPerKm, ?int $workoutType = 0): Activity
{
    $splits = [];
    for ($km = 1; $km <= (int) floor($meters / 1000); $km++) {
        $splits[] = ['km' => $km, 'pace' => PaceFormatter::format((float) $secPerKm), 'elapsed_sec' => $secPerKm, 'distance_m' => 1000];
    }
    $seconds = (int) round($meters / 1000 * $secPerKm);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $date.' 06:00:00', 'distance' => $meters, 'elapsed_time' => $seconds,
        'moving_time' => $seconds, 'workout_type' => $workoutType, 'stream_summary' => ['per_km' => $splits],
    ]);

    return $activity;
}

function confirmedEffort(User $user, string $date, int $meters, int $seconds, ?string $confirmedAt = null): PerformanceEvidence
{
    return PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'kind' => 'race', 'distance_m' => $meters, 'elapsed_time_sec' => $seconds,
        'performed_on' => $date, 'confirmed_at' => $confirmedAt ?? $date.' 12:00:00',
    ]);
}

function supportedTime(VdotEstimator $estimator, array $estimate, float $meters = 10_000.0): float
{
    return $estimator->raceTimeForVdot($estimate['vdot'], $meters);
}

it('reads a recent 5K over older long races, so the 10K lands near an hour rather than the lowest record', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['distance_m' => 10_000, 'goal_time_sec' => 3480, 'race_date' => '2026-11-15']);
    recordRun($user, '2026-05-10', 10_000, 3917);
    recordRun($user, '2026-05-24', 15_000, 6141);
    recordRun($user, '2026-05-31', 21_100, 8792);
    evenRun($user, '2026-08-26', 5_050, 337);

    $estimate = $this->estimator->estimate($user);
    $lowest = $this->estimator->raceTimeForVdot(28.6, 10_000);

    expect(supportedTime($this->estimator, $estimate))->toBeGreaterThan(3540.0)->toBeLessThan(3600.0)
        ->and($lowest)->toBeGreaterThan(3950.0)
        ->and($estimate['source_category'])->toBe('5km')
        ->and($estimate['distance_m'])->toBe(5000)
        ->and($estimate['set_at']->toDateString())->toBe('2026-08-26')
        ->and($estimate['confidence'])->toBe('provisional');
});

it('never counts a run tagged Race on Strava that set no record', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2026-08-01', 10_000, 3_000);
    $tagged = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($tagged)->create([
        'start_date_local' => '2026-09-20 06:00:00', 'distance' => 10_000, 'elapsed_time' => 3_300,
        'moving_time' => 3_300, 'workout_type' => 1, 'stream_summary' => null,
    ]);

    $estimate = $this->estimator->estimate($user);

    expect($estimate['set_at']->toDateString())->toBe('2026-08-01')
        ->and($estimate['source_activity_id'])->not->toBe($tagged->id);
});

it('counts a run that set a distance record over essentially the whole run', function (): void {
    $user = User::factory()->create();
    evenRun($user, '2026-09-20', 10_400, 360);

    $estimate = $this->estimator->estimate($user);

    expect($estimate['source_category'])->toBe('10km')
        ->and($estimate['source_value_sec'])->toEqualWithDelta(3600.0, 0.01);
});

it('never counts a fast segment inside a longer run', function (): void {
    $user = User::factory()->create();
    evenRun($user, '2026-09-20', 12_500, 330);

    expect($this->estimator->estimate($user))->toBeNull();
});

it('does not count a whole-run distance that set no record', function (): void {
    $user = User::factory()->create();
    evenRun($user, '2026-08-01', 5_000, 300);
    evenRun($user, '2026-09-20', 5_000, 330);

    expect($this->estimator->estimate($user)['set_at']->toDateString())->toBe('2026-08-01');
});

it('never reads heart rate as a hard effort', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => '2026-09-20 06:00:00', 'distance' => 8_000, 'elapsed_time' => 2_700, 'workout_type' => null,
        'stream_summary' => ['zone_pct' => ['Z1' => 0, 'Z2' => 0, 'Z3' => 10, 'Z4' => 60, 'Z5' => 30]],
    ]);

    expect($this->estimator->estimate($user))->toBeNull();
});

it('lets the effort closest to the race distance set the supported time', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['distance_m' => 21_098, 'race_date' => '2026-12-01']);
    recordRun($user, '2026-09-01', 5_000, 1_500);
    recordRun($user, '2026-09-10', 10_000, 3_150);

    expect($this->estimator->estimate($user)['distance_m'])->toBe(10000);
});

it('interpolates between the efforts either side of the race distance, weighted toward the closer one', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2026-09-01', 5_000, 1_650);
    recordRun($user, '2026-09-10', 15_000, 5_700);
    $k = FallOffExponent::fit([
        ['date' => Carbon::parse('2026-09-01'), 'distance_m' => 5_000.0, 'time_sec' => 1_650.0],
        ['date' => Carbon::parse('2026-09-10'), 'distance_m' => 15_000.0, 'time_sec' => 5_700.0],
    ]);
    $fromFive = max(1_650 * 2 ** $k, $this->estimator->raceTimeForVdot($this->estimator->vdotFromTimeAndDistance(1_650, 5_000), 10_000));
    $fromFifteen = max(5_700 * (10 / 15) ** $k, $this->estimator->raceTimeForVdot($this->estimator->vdotFromTimeAndDistance(5_700, 15_000), 10_000));
    $belowWeight = log(1.5) / (log(2) + log(1.5));
    $expected = exp($belowWeight * log($fromFive) + (1 - $belowWeight) * log($fromFifteen));

    $estimate = $this->estimator->estimate($user);

    expect(supportedTime($this->estimator, $estimate))->toEqualWithDelta($expected, 3.0)
        ->and($estimate['distance_m'])->toBe(15000)
        ->and($estimate['longest_source_m'])->toBe(15000);
});

it('reads the supported VDOT at 10K when there is no goal race', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2026-09-20', 5_000, 1_500);
    $tenK = max(1_500 * 2 ** FallOffExponent::DEFAULT_UP_TO_10K, $this->estimator->raceTimeForVdot($this->estimator->vdotFromTimeAndDistance(1_500, 5_000), 10_000));

    $estimate = $this->estimator->estimate($user);

    expect($estimate['race_distance_m'])->toBe(10_000.0)
        ->and($estimate['vdot'])->toBe(round($this->estimator->vdotFromTimeAndDistance($tenK, 10_000), 1));
});

it('reads the supported VDOT at the goal race distance with that distance\'s default fall-off', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['distance_m' => 21_098, 'race_date' => '2026-12-01']);
    recordRun($user, '2026-09-20', 10_000, 3_000);
    $half = 3_000 * 2.1098 ** FallOffExponent::DEFAULT_HALF;

    $estimate = $this->estimator->estimate($user);

    expect($estimate['race_distance_m'])->toBe(21_098.0)
        ->and(supportedTime($this->estimator, $estimate, 21_098))->toEqualWithDelta($half, 15.0);
});

it('slows a projection with the athlete\'s own fall-off fitted from a close cluster', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['distance_m' => 21_098, 'race_date' => '2026-12-01']);
    recordRun($user, '2026-09-01', 5_000, 1_500);
    recordRun($user, '2026-09-20', 10_000, 3_000 * 1);
    $fitted = FallOffExponent::fit([
        ['date' => Carbon::parse('2026-09-01'), 'distance_m' => 5_000.0, 'time_sec' => 1_500.0],
        ['date' => Carbon::parse('2026-09-20'), 'distance_m' => 10_000.0, 'time_sec' => 3_000.0],
    ]);

    $estimate = $this->estimator->estimate($user);

    expect($fitted)->toBe(FallOffExponent::MIN)
        ->and(supportedTime($this->estimator, $estimate, 21_098))->toBeLessThan(3_000 * 2.1098 ** FallOffExponent::DEFAULT_HALF);
});

it('never lets the supported time sit slower than a recent training run of the race distance', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2026-09-01', 5_000, 1_800);
    $run = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($run)->create([
        'start_date_local' => '2026-09-25 06:00:00', 'distance' => 10_000, 'elapsed_time' => 3_300, 'workout_type' => 0, 'stream_summary' => null,
    ]);

    $estimate = $this->estimator->estimate($user);

    expect(supportedTime($this->estimator, $estimate))->toEqualWithDelta(3_300.0, 3.0)
        ->and($estimate['source_category'])->toBe('training_run')
        ->and($estimate['confidence'])->toBe('provisional');
});

it('never lets a training run lower the supported time or stand in for an effort', function (): void {
    $user = User::factory()->create();
    $slow = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($slow)->create([
        'start_date_local' => '2026-09-25 06:00:00', 'distance' => 12_000, 'elapsed_time' => 6_000, 'workout_type' => 0, 'stream_summary' => null,
    ]);

    expect($this->estimator->estimate($user))->toBeNull();

    recordRun($user, '2026-09-01', 10_000, 3_000);
    $this->estimator->forget($user);

    expect($this->estimator->estimate($user)['source_category'])->toBe('10km');
});

it('lifts on unconfirmed records by at most one VDOT a week, and a confirmed slower effort drops it at once', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2026-08-01', 10_000, 3_600);
    FitnessAnchor::query()->create([
        'user_id' => $user->id, 'vdot' => 1, 'quality_vdot' => 1, 'source_category' => 'race', 'source_value_sec' => 3600,
        'set_at' => '2026-08-01', 'captured_at' => '2026-08-02 08:00:00',
    ]);
    $baseline = $this->estimator->estimate($user, Carbon::parse('2026-09-09'))['vdot'];
    recordRun($user, '2026-09-10', 10_000, 3_200);
    $this->estimator->forget($user);
    $uncapped = round($this->estimator->vdotFromTimeAndDistance(3_200, 10_000), 1);

    expect($this->estimator->estimate($user, Carbon::parse('2026-09-10'))['vdot'])->toBe(round($baseline + 1.0, 1))
        ->and($this->estimator->estimate($user, Carbon::parse('2026-09-16'))['vdot'])->toBe(round($baseline + 1.0, 1))
        ->and($this->estimator->estimate($user, Carbon::parse('2026-09-17'))['vdot'])->toBe(round($baseline + 2.0, 1))
        ->and($this->estimator->estimate($user, Carbon::parse('2026-10-05'))['vdot'])->toBe(min($uncapped, round($baseline + 4.0, 1)));

    confirmedEffort($user, '2026-10-05', 10_000, 3_700);
    $this->estimator->forget($user);

    expect($this->estimator->estimate($user)['vdot'])->toBe(round($this->estimator->vdotFromTimeAndDistance(3_700, 10_000), 1));
});

it('applies a confirmed effort at once', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2026-08-01', 10_000, 3_600);
    FitnessAnchor::query()->create([
        'user_id' => $user->id, 'vdot' => 1, 'quality_vdot' => 1, 'source_category' => 'race', 'source_value_sec' => 3600,
        'set_at' => '2026-08-01', 'captured_at' => '2026-08-02 08:00:00',
    ]);
    confirmedEffort($user, '2026-09-10', 10_000, 3_200);

    $estimate = $this->estimator->estimate($user, Carbon::parse('2026-09-10'));

    expect($estimate['vdot'])->toBe(round($this->estimator->vdotFromTimeAndDistance(3_200, 10_000), 1))
        ->and($estimate['confidence'])->toBe('confirmed');
});

it('gives the same capped answer for a past day whether read then or after later efforts landed', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2026-07-01', 10_000, 3_700);
    FitnessAnchor::query()->create([
        'user_id' => $user->id, 'vdot' => 1, 'quality_vdot' => 1, 'source_category' => 'race', 'source_value_sec' => 3700,
        'set_at' => '2026-07-01', 'captured_at' => '2026-07-02 08:00:00',
    ]);
    recordRun($user, '2026-08-01', 10_000, 3_300);
    $then = $this->estimator->estimate($user, Carbon::parse('2026-08-12'));

    recordRun($user, '2026-08-20', 10_000, 3_000);
    $fresh = app(VdotEstimator::class);
    $fresh->forget($user);
    $later = $fresh->estimate($user, Carbon::parse('2026-10-01'));

    expect($fresh->estimate($user, Carbon::parse('2026-08-12'))['vdot'])->toBe($then['vdot'])
        ->and($later['vdot'])->toBeGreaterThan($then['vdot']);
});

it('falls back to the newest older effort, labelled stale, when nothing qualifies in 16 weeks', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2025-12-01', 10_000, 3_000);
    recordRun($user, '2026-04-01', 21_100, 9_000);

    $estimate = $this->estimator->estimate($user);

    expect($estimate['stale'])->toBeTrue()
        ->and($estimate['confidence'])->toBe('stale')
        ->and($estimate['set_at']->toDateString())->toBe('2026-04-01');
});

it('keeps old confirmed evidence available with stale confidence', function (): void {
    $user = User::factory()->create();
    confirmedEffort($user, '2024-10-01', 5_000, 1_500);

    $estimate = $this->estimator->estimate($user);

    expect($estimate['confidence'])->toBe('stale')
        ->and($estimate['stale'])->toBeTrue();
});

it('anchors fitness to confirmed races and tests with explicit provenance', function (): void {
    $user = User::factory()->create();
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'kind' => 'test', 'distance_m' => 5000, 'elapsed_time_sec' => 1500,
        'performed_on' => Carbon::today()->subWeek(), 'confirmed_at' => now(),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result['confidence'])->toBe('confirmed')->and($result['source_category'])->toBe('confirmed_test')
        ->and($result['stale'])->toBeFalse()->and($result['evidence_id'])->toBeInt();
});

it('reads a confirmed effort in place of the same run\'s unconfirmed record', function (): void {
    $user = User::factory()->create();
    $activity = recordRun($user, '2026-09-20', 10_000, 3_000);
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'activity_id' => $activity->id, 'kind' => 'race', 'distance_m' => 10_000,
        'elapsed_time_sec' => 3_050, 'performed_on' => '2026-09-20', 'confirmed_at' => now(),
    ]);

    $estimate = $this->estimator->estimate($user);

    expect($estimate['source_category'])->toBe('confirmed_race')
        ->and($estimate['source_value_sec'])->toBe(3050.0);
});

it('marks materially different recent confirmed distances as conflicting', function (): void {
    $user = User::factory()->create();
    confirmedEffort($user, '2026-09-01', 5_000, 1_200);
    confirmedEffort($user, '2026-09-10', 10_000, 3_300);

    expect($this->estimator->estimate($user)['confidence'])->toBe('conflicting');
});

it('excludes an evidence confirmation made after the historical as-of date', function (): void {
    $user = User::factory()->create();
    confirmedEffort($user, '2026-09-20', 5_000, 1_500, '2026-10-01 12:00:00');

    expect($this->estimator->estimate($user, Carbon::parse('2026-09-30')))->toBeNull();
});

it('does not use an effort run after the requested as-of date', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2026-10-06', 5_000, 1_500);

    expect($this->estimator->estimate($user))->toBeNull();
});

it('anchors quality work on recent short records above the supported VDOT', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2026-09-01', 21_100, 9_000);
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 1_500, 'set_at' => '2026-09-15']);

    $estimate = $this->estimator->estimate($user);

    expect($estimate['quality_vdot'])->toBe(round($this->estimator->vdotFromTimeAndDistance(1_500, 5_000), 1))
        ->and($estimate['quality_vdot'])->toBeGreaterThan($estimate['vdot'])
        ->and($estimate['quality_source']['source_category'])->toBe('5km');
});

it('never lets the quality anchor fall below the supported VDOT', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2026-09-01', 10_000, 3_000);
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 2_400, 'set_at' => '2026-09-15']);

    $estimate = $this->estimator->estimate($user);

    expect($estimate['quality_vdot'])->toBe($estimate['vdot'])
        ->and($estimate['quality_source'])->toBeNull();
});

it('will not let a lone short record establish the quality anchor', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2026-09-01', 10_000, 3_600);
    PersonalRecord::factory()->for($user)->create(['category' => '1km', 'value_sec' => 200, 'set_at' => '2026-09-15']);

    $estimate = $this->estimator->estimate($user);

    expect($estimate['quality_vdot'])->toBe($estimate['vdot']);
});

it('reads quality from recent confirmed evidence once the athlete has any', function (): void {
    $user = User::factory()->create();
    confirmedEffort($user, '2026-04-01', 21_100, 9_000);
    confirmedEffort($user, '2026-09-15', 5_000, 1_500);

    $estimate = $this->estimator->estimate($user);

    expect($estimate['quality_vdot'])->toBeGreaterThanOrEqual($estimate['vdot']);
});

it('captures a provisional anchor once, at the first estimate', function (): void {
    $user = User::factory()->create();
    $this->estimator->captureProvisionalAnchor($user);

    expect(FitnessAnchor::query()->where('user_id', $user->id)->exists())->toBeFalse();

    recordRun($user, '2026-09-20', 10_000, 3_000);
    $this->estimator->forget($user);
    $this->estimator->captureProvisionalAnchor($user, Carbon::parse('2026-09-21 08:00:00'));
    $this->estimator->captureProvisionalAnchor($user, Carbon::parse('2026-10-01 08:00:00'));

    expect(FitnessAnchor::query()->where('user_id', $user->id)->sole()->captured_at->toDateString())->toBe('2026-09-21');
});

it('formula computes a believable VDOT for a known marathon time', function (): void {
    expect($this->estimator->vdotFromTimeAndDistance(10_800, 42_195))->toBeFloat()->toBeGreaterThan(50)->toBeLessThan(58);
});

it('returns null for zero or negative inputs', function (): void {
    expect($this->estimator->vdotFromTimeAndDistance(0, 5_000))->toBeNull()
        ->and($this->estimator->vdotFromTimeAndDistance(1_200, 0))->toBeNull();
});

it('inverts a VDOT back to the race time it supports at a distance', function (float $timeSec, float $distanceM): void {
    $vdot = $this->estimator->vdotFromTimeAndDistance($timeSec, $distanceM);

    expect($this->estimator->raceTimeForVdot($vdot, $distanceM))->toEqualWithDelta($timeSec, 1.0);
})->with([[1500.0, 5000.0], [4200.0, 10000.0], [11_400.0, 42195.0]]);

it('supports no race time for a non-positive VDOT or distance', function (): void {
    expect($this->estimator->raceTimeForVdot(0.0, 10_000.0))->toBeNull()
        ->and($this->estimator->raceTimeForVdot(50.0, 0.0))->toBeNull();
});

it('summarises the source of an estimate for display', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2026-09-20', 10_000, 3_000);

    expect(VdotEstimator::sourceSummary($this->estimator->estimate($user)))->toMatchArray([
        'category' => '10km', 'set_at' => '2026-09-20', 'stale' => false, 'confidence' => 'provisional', 'distance_m' => 10000,
    ]);
});

it('caps a rise from a training-run floor like any unconfirmed record', function (): void {
    $user = User::factory()->create();
    recordRun($user, '2026-08-01', 10_000, 3_600);
    FitnessAnchor::query()->create([
        'user_id' => $user->id, 'vdot' => 1, 'quality_vdot' => 1, 'source_category' => 'race', 'source_value_sec' => 3600,
        'set_at' => '2026-08-01', 'captured_at' => '2026-08-02 08:00:00',
    ]);
    $baseline = $this->estimator->estimate($user, Carbon::parse('2026-09-09'))['vdot'];
    $run = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($run)->create([
        'start_date_local' => '2026-09-10 06:00:00', 'distance' => 12_000, 'elapsed_time' => 3_600, 'workout_type' => 0, 'stream_summary' => null,
    ]);
    $this->estimator->forget($user);

    $estimate = $this->estimator->estimate($user, Carbon::parse('2026-09-10'));

    expect($estimate['vdot'])->toBe(round($baseline + 1.0, 1))
        ->and($estimate['source_category'])->toBe('training_run');
});
