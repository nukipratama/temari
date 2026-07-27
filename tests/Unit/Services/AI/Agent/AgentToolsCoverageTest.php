<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\AI\Agent\Tools\EffortContextTool;
use App\Services\AI\Agent\Tools\HrZonesTool;
use App\Services\AI\Agent\Tools\KmSplitsTool;
use App\Services\AI\Agent\Tools\RecentBaselineTool;
use App\Services\AI\Agent\Tools\RunSummaryTool;
use App\Services\AI\Agent\Tools\TerrainTool;
use App\Services\AI\Agent\Tools\TrainingLoadTool;
use App\Services\AI\Agent\Tools\TrainingPacesTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\Metrics\RunBaseline;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * @return array{activity: Activity, detail: ActivityDetail}
 */
function agentToolFixture(): array
{
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 5000.0,
        'moving_time' => 1500,
    ]);

    return ['activity' => $activity, 'detail' => $detail];
}

// ── the shape every tool shares ───────────────────────────────────────

it('declares an argument-free schema, so no tool can be pointed at another run', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();

    expect(new RunSummaryTool($a, $d)->parameters())->toEqual([
        'type' => 'object',
        'properties' => (object) [],
        'required' => [],
        'additionalProperties' => false,
    ]);
});

it('names every tool in snake_case with a description the model can choose on', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();

    $tools = [
        new RunSummaryTool($a, $d),
        new KmSplitsTool($a, $d),
        new HrZonesTool($a, $d),
        new TerrainTool($a, $d),
        new WeatherTool($a, $d),
        new EffortContextTool($a, $d, app(RelativeEffort::class)),
        new TrainingLoadTool($a, $d, new TrainingLoad()),
        new RecentBaselineTool($a, $d, new RunBaseline()),
        new TrainingPacesTool($a, $d, app(VdotEstimator::class), app(TrainingPaceCalculator::class)),
    ];

    foreach ($tools as $tool) {
        expect($tool->name())->toMatch('/^get_[a-z_]+$/')
            ->and($tool->description())->not->toBe('');
    }
});

// ── RunSummaryTool ────────────────────────────────────────────────────

it('reads the run basics, doubling the one-leg cadence Strava stores', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['average_heartrate' => 155.0, 'max_heartrate' => 178, 'average_cadence' => 83.5]);

    expect(new RunSummaryTool($a, $d->fresh())->handle([]))->toMatchArray([
        'distance_km' => 5.0,
        'moving_time_sec' => 1500,
        'avg_hr' => 155.0,
        'max_hr' => 178,
        'avg_cadence_spm' => 167,
    ]);
});

it('leaves cadence null rather than doubling a missing value', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['average_cadence' => null]);

    expect(new RunSummaryTool($a, $d->fresh())->handle([])['avg_cadence_spm'])->toBeNull();
});

// ── KmSplitsTool ──────────────────────────────────────────────────────

it('reads the splits with the pace spread already banded into words', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['stream_summary' => [
        'per_km' => [['km' => 1, 'pace' => '6:00'], ['km' => 2, 'pace' => '6:00']],
        'partial_split' => ['distance_m' => 700, 'pace' => '5:30', 'avg_hr' => 158],
        'pace_variability_sec' => 11.3,
        'negative_split' => true,
    ]]);

    $reading = new KmSplitsTool($a, $d->fresh())->handle([]);

    // Banded, never the raw figure: the model quoted "pace variability 68 detik
    // per km" at users, a number none of them can act on.
    expect($reading['pace_consistency'])->toBe('cukup rata')
        ->and($reading)->not->toHaveKey('pace_variability_sec')
        ->and($reading['per_km'])->toHaveCount(2)
        ->and($reading['finish_partial'])->toMatchArray(['distance_m' => 700, 'pace' => '5:30'])
        ->and($reading['negative_split'])->toBeTrue();
});

it('leaves the finish partial null when the run ends on a whole km', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['stream_summary' => ['per_km' => [['km' => 1, 'pace' => '6:00']]]]);

    expect(new KmSplitsTool($a, $d->fresh())->handle([])['finish_partial'])->toBeNull();
});

it('reads null splits without fataling when there is no stream summary', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['stream_summary' => null]);

    expect(new KmSplitsTool($a, $d->fresh())->handle([]))->toBe([
        'per_km' => null,
        'finish_partial' => null,
        'negative_split' => null,
        'pace_consistency' => null,
    ]);
});

// ── HrZonesTool ───────────────────────────────────────────────────────

it('reads the zone split in both percent and minutes, with the session TRIMP', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update([
        'trimp_edwards' => 92.4,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 70, 'Z3' => 30],
            'time_in_zone_min' => ['Z2' => 32, 'Z3' => 14],
        ],
    ]);

    expect(new HrZonesTool($a, $d->fresh())->handle([]))->toMatchArray([
        'zone_pct' => ['Z2' => 70, 'Z3' => 30],
        'time_in_zone_min' => ['Z2' => 32, 'Z3' => 14],
        'trimp' => 92.4,
    ]);
});

it('reads empty zones for a run with no heart rate', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['stream_summary' => null, 'trimp_edwards' => null]);

    $reading = new HrZonesTool($a, $d->fresh())->handle([]);

    expect($reading['zone_pct'])->toBe([])
        ->and($reading['time_in_zone_min'])->toBeNull()
        ->and($reading['trimp'])->toBeNull();
});

// ── TerrainTool ───────────────────────────────────────────────────────

it('reads Strava elevation gain alongside the steepest grade and the flat-adjusted pace', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update([
        'total_elevation_gain' => 48,
        'stream_summary' => ['max_grade_pct' => 9.5, 'gap_pace' => '5:40'],
    ]);

    expect(new TerrainTool($a, $d->fresh())->handle([]))->toMatchArray([
        'elevation_gain_m' => 48.0,
        'max_grade_pct' => 9.5,
        'gap_pace' => '5:40',
    ]);
});

// ── WeatherTool ───────────────────────────────────────────────────────

it('reads the weather with the rain flag carrying whether it was observed or forecast', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update([
        'weather_temp_c' => 32,
        'weather_humidity_pct' => 80,
        'weather_rain_detected' => true,
        'weather_rain_is_forecast' => true,
        'weather_wind_speed_kmh' => 28,
    ]);

    expect(new WeatherTool($a, $d->fresh())->handle([]))->toMatchArray([
        'weather_temp_c' => 32,
        'weather_humidity_pct' => 80,
        'weather_rain' => true,
        'weather_rain_source' => 'forecast',
        'weather_wind_speed_kmh' => 28,
    ]);
});

// ── EffortContextTool ─────────────────────────────────────────────────

it('reads a Strava-tagged workout as a tagged intent', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update([
        'workout_type' => 3, // Strava "Workout"
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 90, 'Z3' => 10]],
    ]);

    expect(new EffortContextTool($a, $d->fresh(), app(RelativeEffort::class))->handle([])['session_intent'])
        ->toBe(['intent' => 'workout', 'source' => 'tagged']);
});

it('infers a workout intent from a Z3-Z4 heavy untagged run', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update([
        'workout_type' => null,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 15, 'Z3' => 47, 'Z4' => 34, 'Z5' => 4]],
    ]);

    expect(new EffortContextTool($a, $d->fresh(), app(RelativeEffort::class))->handle([])['session_intent'])
        ->toBe(['intent' => 'workout', 'source' => 'inferred']);
});

it('reads relative effort with no comparison when the history is still one run deep', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['trimp_edwards' => 92.4]);

    expect(new EffortContextTool($a, $d->fresh(), app(RelativeEffort::class))->handle([])['relative_effort'])
        ->toBe(['trimp' => 92.4, 'baseline' => null, 'ratio' => null, 'band' => null]);
});

// ── TrainingLoadTool + RecentBaselineTool ─────────────────────────────

it('reads the 28-day baseline and the load state from a prior run', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $prior = Activity::factory()->for($a->user)->analyzed()->create();
    ActivityDetail::factory()->for($prior)->create([
        'start_date_local' => Carbon::today()->subDays(5),
        'distance' => 10000.0,
        'moving_time' => 3600, // 6:00/km
        'average_heartrate' => 150.0,
        'trimp_edwards' => 80.0,
        'stream_summary' => ['decoupling_pct' => 6.0, 'time_in_zone_min' => ['Z2' => 40]],
    ]);

    $baseline = new RecentBaselineTool($a, $d, new RunBaseline())->handle([])['recent_baseline_28d'];
    $load = new TrainingLoadTool($a, $d, new TrainingLoad())->handle([]);

    expect($baseline)->toMatchArray([
        'runs' => 1,
        'avg_pace_sec_per_km' => 360,
        'avg_hr' => 150,
        'avg_decoupling_pct' => 6.0,
    ])
        ->and($load)->toHaveKeys(['acute_7d', 'chronic_42d', 'form', 'form_status']);
});

it('reads a null training load rather than inventing one with no TRIMP history', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['trimp_edwards' => null]);

    expect(new TrainingLoadTool($a, $d->fresh(), new TrainingLoad())->handle([]))->toBe(['training_load' => null]);
});

it('excludes the run being narrated from its own baseline', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['average_heartrate' => 190.0]);

    expect(new RecentBaselineTool($a, $d->fresh(), new RunBaseline())->handle([])['recent_baseline_28d'])
        ->toBeNull();
});

// ── TrainingPacesTool ─────────────────────────────────────────────────

it('reads easy and threshold paces derived from the runner VDOT', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    PersonalRecord::factory()->for($a->user)->create(['category' => '5km', 'value_sec' => 1200]);

    $reading = new TrainingPacesTool($a, $d, app(VdotEstimator::class), app(TrainingPaceCalculator::class))->handle([]);

    expect($reading['easy_pace_sec'])->toBeInt()
        ->and($reading['threshold_pace_sec'])->toBeInt()
        ->and($reading['easy_pace_sec'])->toBeGreaterThan($reading['threshold_pace_sec']);
});

it('reads null paces when the runner has no VDOT-eligible PR', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();

    $reading = new TrainingPacesTool($a, $d, app(VdotEstimator::class), app(TrainingPaceCalculator::class))->handle([]);

    expect($reading['easy_pace_sec'])->toBeNull()
        ->and($reading['threshold_pace_sec'])->toBeNull();
});
