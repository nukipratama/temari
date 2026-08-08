<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Services\AI\Agent\Tools\CardIdentityTool;
use App\Services\AI\Agent\Tools\EffortContextTool;
use App\Services\AI\Agent\Tools\FeaturedCardTool;
use App\Services\AI\Agent\Tools\HrZonesTool;
use App\Services\AI\Agent\Tools\KmSplitsTool;
use App\Services\AI\Agent\Tools\LatestPastYouTool;
use App\Services\AI\Agent\Tools\RecentRunsTool;
use App\Services\AI\Agent\Tools\PastYouTool;
use App\Services\AI\Agent\Tools\PersonalRecordsTool;
use App\Services\AI\Agent\Tools\RecentBaselineTool;
use App\Services\AI\Agent\Tools\RunSummaryTool;
use App\Services\AI\Agent\Tools\TerrainTool;
use App\Services\AI\Agent\Tools\TrainingLoadTool;
use App\Services\AI\Agent\Tools\TrainingPacesTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\AI\Agent\Tools\WeekStateTool;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\PastYouMatcher;
use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\AI\Agent\Tools\ProgressionSignalTool;
use App\Services\AI\Agent\Tools\PersonaMixTool;
use App\Services\Run\ProgressionSeriesBuilder;
use Illuminate\Support\Facades\DB;
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
        new TrainingLoadTool($a->user, $d->start_date_local, new TrainingLoad()),
        new RecentBaselineTool($a->user, $d->start_date_local, new ResolveRunBaselineAction()),
        new TrainingPacesTool($a->user, $d->start_date_local, app(VdotEstimator::class), app(TrainingPaceCalculator::class)),
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

it('rounds the run distance to the one decimal the copy rule allows', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['distance' => 10_470.0]);

    expect(new RunSummaryTool($a, $d->fresh())->handle([]))->toMatchArray(['distance_km' => 10.5]);
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
        'omitted_km' => 0,
        'fastest_km' => null,
        'slowest_km' => null,
        'finish_partial' => null,
        'negative_split' => null,
        'pace_consistency' => null,
    ]);
});

// The narrator prompt asks for "1-2 km paling menarik". Naming the extremes
// answers that directly instead of leaving the model to scan the table for them.
it('names the fastest and slowest kilometre outright', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['stream_summary' => ['per_km' => [
        ['km' => 1, 'pace' => '6:00'],
        ['km' => 2, 'pace' => '5:20'],
        ['km' => 3, 'pace' => '6:30'],
    ]]]);

    $reading = new KmSplitsTool($a, $d->fresh())->handle([]);

    expect($reading['fastest_km'])->toBe(2)
        ->and($reading['slowest_km'])->toBe(3)
        ->and($reading['omitted_km'])->toBe(0)
        ->and($reading['per_km'])->toHaveCount(3);
});

it('leaves the extremes null when no split carries a readable pace', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['stream_summary' => ['per_km' => [['km' => 1, 'pace' => '-']]]]);

    $reading = new KmSplitsTool($a, $d->fresh())->handle([]);

    expect($reading['fastest_km'])->toBeNull()
        ->and($reading['slowest_km'])->toBeNull();
});

it('samples a long run down while keeping the opening, the finish and both extremes', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();

    // 30 km, with the extremes buried mid-run where an even spread could miss them.
    $perKm = [];
    foreach (range(1, 30) as $km) {
        $perKm[] = ['km' => $km, 'pace' => '6:00'];
    }
    $perKm[12] = ['km' => 13, 'pace' => '4:30'];
    $perKm[19] = ['km' => 20, 'pace' => '7:45'];
    $d->update(['stream_summary' => ['per_km' => $perKm]]);

    $reading = new KmSplitsTool($a, $d->fresh())->handle([]);
    $kept = array_column($reading['per_km'], 'km');

    expect($reading['fastest_km'])->toBe(13)
        ->and($reading['slowest_km'])->toBe(20)
        // Opening and finish survive, so a closing-kick story is still readable.
        ->and($kept)->toContain(1)
        ->and($kept)->toContain(30)
        ->and($kept)->toContain(13)
        ->and($kept)->toContain(20)
        ->and(count($kept))->toBeLessThan(30)
        ->and($reading['omitted_km'])->toBe(30 - count($kept))
        // Still ordered by km, so the run reads front to back.
        ->and($kept)->toBe(array_values(array_unique($kept)))
        ->and($kept === array_values($kept) && $kept === collect($kept)->sort()->values()->all())->toBeTrue();
});

it('leaves a run at the sample size untouched', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $perKm = [];
    foreach (range(1, 12) as $km) {
        $perKm[] = ['km' => $km, 'pace' => '6:00'];
    }
    $d->update(['stream_summary' => ['per_km' => $perKm]]);

    $reading = new KmSplitsTool($a, $d->fresh())->handle([]);

    expect($reading['per_km'])->toHaveCount(12)
        ->and($reading['omitted_km'])->toBe(0);
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

    $baseline = new RecentBaselineTool($a->user, $d->start_date_local, new ResolveRunBaselineAction())->handle([])['recent_baseline_28d'];
    $load = new TrainingLoadTool($a->user, $d->start_date_local, new TrainingLoad())->handle([])['training_load'];

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

    expect(new TrainingLoadTool($a->user, $d->start_date_local, new TrainingLoad())->handle([]))->toBe(['training_load' => null]);
});

it('excludes the run being narrated from its own baseline', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['average_heartrate' => 190.0]);

    expect(new RecentBaselineTool($a->user, $d->start_date_local, new ResolveRunBaselineAction(), $a->id)->handle([])['recent_baseline_28d'])
        ->toBeNull();
});

// ── TrainingPacesTool ─────────────────────────────────────────────────

it('reads easy and threshold paces derived from the runner VDOT', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    PersonalRecord::factory()->for($a->user)->create(['category' => '5km', 'value_sec' => 1200]);

    $reading = new TrainingPacesTool($a->user, $d->start_date_local, app(VdotEstimator::class), app(TrainingPaceCalculator::class))->handle([]);

    expect($reading['easy_pace_sec'])->toBeInt()
        ->and($reading['threshold_pace_sec'])->toBeInt()
        ->and($reading['easy_pace_sec'])->toBeGreaterThan($reading['threshold_pace_sec']);
});

it('reads null paces when the runner has no VDOT-eligible PR', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();

    $reading = new TrainingPacesTool($a->user, $d->start_date_local, app(VdotEstimator::class), app(TrainingPaceCalculator::class))->handle([]);

    expect($reading['easy_pace_sec'])->toBeNull()
        ->and($reading['threshold_pace_sec'])->toBeNull();
});

// ── PastYouTool ───────────────────────────────────────────────────────

it('reads a comparable past run of the same user, signed so faster reads positive', function (): void {
    // Current run: 5 km in 1500 s (5:00/km, threshold band).
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['weather_temp_c' => null]); // don't let the random factory temp gate the match
    // A comparable run 30 days earlier: same distance band + threshold pace, but slower.
    $past = Activity::factory()->for($a->user)->analyzed()->create();
    ActivityDetail::factory()->for($past)->create([
        'start_date_local' => Carbon::today()->subDays(30),
        'distance' => 5000.0,
        'moving_time' => 1560, // 5:12/km, slower than the current 5:00/km
        'weather_temp_c' => null,
    ]);

    $reading = new PastYouTool($a, $d->fresh(), app(PastYouMatcher::class))->handle([])['past_you'];

    expect($reading)->not->toBeNull()
        ->and($reading['days_ago'])->toBe(30)
        ->and($reading['pace_diff_sec'])->toBeGreaterThan(0.0) // current is faster
        ->and($reading['past_km'])->toBe(5.0);
});

it('reads a null past you rather than reaching for an incomparable run', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();

    expect(new PastYouTool($a, $d, app(PastYouMatcher::class))->handle([])['past_you'])->toBeNull();
});

// ── PersonalRecordsTool ───────────────────────────────────────────────

it('reads the records this run broke', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    PersonalRecord::factory()->for($a->user)->create([
        'activity_id' => $a->id,
        'category' => '5km',
        'value_sec' => 1500,
    ]);

    expect(new PersonalRecordsTool($a, $d)->handle([])['personal_records'])
        ->toBe([['category' => '5km', 'value_sec' => 1500.0]]);
});

it('reads an empty record list for a run that broke nothing, so no PR can be invented', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    // A record the user holds from some *other* run must not count as this run's.
    PersonalRecord::factory()->for($a->user)->create(['category' => '5km', 'value_sec' => 1400]);

    expect(new PersonalRecordsTool($a, $d)->handle([])['personal_records'])->toBe([]);
});

// ── CardIdentityTool ──────────────────────────────────────────────────

it('reads the card identity with badge slugs humanised, so no raw code reaches the prompt', function (): void {
    ['activity' => $a] = agentToolFixture();
    $card = RunCard::factory()->create([
        'activity_id' => $a->id,
        'rarity' => 'rare',
        'special_move' => 'Pembara Sabar',
        'badges' => ['long_slow_distance', 'pejuang_hujan', 'not_a_real_badge'],
    ]);

    $reading = new CardIdentityTool($card)->handle([]);

    expect($reading['rarity'])->toBe('rare')
        ->and($reading['rarity_label'])->toBe('Langka')
        ->and($reading['special_move'])->toBe('Pembara Sabar')
        ->and($reading['badges'])->toBe(['Long Slow Distance', 'Pejuang Hujan']);
});

it('reads an empty badge list when the card carries none', function (): void {
    ['activity' => $a] = agentToolFixture();
    $card = RunCard::factory()->create(['activity_id' => $a->id, 'badges' => []]);

    expect(new CardIdentityTool($card)->handle([])['badges'])->toBe([]);
});

// ── FeaturedCardTool ──────────────────────────────────────────────────

it('reads the featured card with badges humanised and capped at three tags', function (): void {
    ['activity' => $a] = agentToolFixture();
    $card = RunCard::factory()->create([
        'activity_id' => $a->id,
        'rarity' => 'legendary',
        'special_move' => 'Langkah Sunyi',
        'badges' => ['anak_pagi', 'negative_split', 'tahan_diri', 'hari_panas'],
    ]);

    $reading = new FeaturedCardTool($card->fresh()->load('activity.detail'))->handle([]);

    expect($reading['name'])->toBe('Langkah Sunyi')
        ->and($reading['rarity_label'])->toBe('Legendaris')
        ->and($reading['km'])->toBe('5km')
        ->and($reading['tags'])->toHaveCount(3)
        ->and($reading['tags'][0])->toBe('Anak Pagi');
});

it('reads a dash for the distance when the card run has none', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['distance' => null]);
    $card = RunCard::factory()->create(['activity_id' => $a->id, 'badges' => []]);

    expect(new FeaturedCardTool($card->fresh()->load('activity.detail'))->handle([])['km'])->toBe('-');
});

// ── WeekStateTool ─────────────────────────────────────────────────────

it('reads the whole week picture in one call, since it is produced in one query pass', function (): void {
    ['activity' => $a] = agentToolFixture();

    $reading = new WeekStateTool($a->user, Carbon::today(), new TrainingLoad())->handle([]);

    expect($reading)->toHaveKeys([
        'this_week_runs', 'last_week_runs', 'this_week_km', 'last_week_km',
        'recovery_hours', 'ran_today', 'days_since_last_run', 'form_status',
        'time_bucket', 'consecutive_weeks_active', 'fitness_trend',
        'volume_ramp_pct', 'readiness_ceiling', 'build_nudge',
    ]);
});

it('reads a ran_today of true on a day the runner already ran', function (): void {
    ['activity' => $a] = agentToolFixture();

    expect(new WeekStateTool($a->user, Carbon::today(), new TrainingLoad())->handle([])['ran_today'])
        ->toBeTrue();
});

// ── RecentRunsTool ────────────────────────────────────────────────────

it('reads the recent runs as mood, distance, intensity and a one-liner', function (): void {
    ['activity' => $a] = agentToolFixture();
    StoryLine::factory()->create([
        'user_id' => $a->user_id,
        'activity_id' => $a->id,
        'kind' => StoryLine::KIND_POST_RUN,
    ]);
    // The timeline's one-liner IS the post-run speech, so a run without a Done
    // speech row is not yet a verdict.
    Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $a->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'status' => AnalysisStatus::Done,
        'content' => 'Lari yang rapi.',
    ]);

    $reading = new RecentRunsTool($a->user, Carbon::today(), app(VerdictNarrator::class))->handle([]);

    expect($reading['recent_runs'])->toBeArray()->toHaveCount(1)
        ->and($reading['recent_runs'][0])->toHaveKeys(['mood', 'km', 'intensity', 'oneline']);
});

it('reads an empty recent-runs list for a runner with no history', function (): void {
    $user = User::factory()->create();

    expect(new RecentRunsTool($user, Carbon::today(), app(VerdictNarrator::class))->handle([])['recent_runs'])
        ->toBe([]);
});

// ── LatestPastYouTool ─────────────────────────────────────────────────

it('compares the runner latest run against a similar one of their own', function (): void {
    ['activity' => $a, 'detail' => $d] = agentToolFixture();
    $d->update(['weather_temp_c' => null]);
    $past = Activity::factory()->for($a->user)->analyzed()->create();
    ActivityDetail::factory()->for($past)->create([
        'start_date_local' => Carbon::today()->subDays(30),
        'distance' => 5000.0,
        'moving_time' => 1560,
        'weather_temp_c' => null,
    ]);

    $reading = new LatestPastYouTool($a->user, Carbon::today(), app(PastYouMatcher::class))->handle([]);

    expect($reading['past_you'])->not->toBeNull()
        ->and($reading['past_you']['days_ago'])->toBe(30);
});

it('reads a null past you when the runner has never run', function (): void {
    $user = User::factory()->create();

    expect(new LatestPastYouTool($user, Carbon::today(), app(PastYouMatcher::class))->handle([])['past_you'])
        ->toBeNull();
});

// ── ProgressionSignalTool ────────────────────────────────────────────

it('names the distance the runner has improved most, from one series build', function (): void {
    $user = User::factory()->create();

    // Two categories with two timed efforts each; 10k improved by more.
    foreach ([['5km', 5000.0, [1500, 1440]], ['10km', 10000.0, [3300, 3000]]] as [$category, $distance, $times]) {
        PersonalRecord::factory()->for($user)->create([
            'category' => $category,
            'value_sec' => min($times),
        ]);
        foreach ($times as $index => $seconds) {
            $activity = Activity::factory()->for($user)->analyzed()->create();
            ActivityDetail::factory()->for($activity)->create([
                'start_date_local' => Carbon::today()->subDays(60 - $index * 10),
                'distance' => $distance,
                'elapsed_time' => $seconds,
            ]);
        }
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $reading = new ProgressionSignalTool($user, Carbon::today(), app(ProgressionSeriesBuilder::class))->handle([]);

    expect($reading['progression_signal'])->not->toBeNull()
        ->and($reading['progression_signal']['delta_sec'])->toBe(300)
        // One read for the records, one series build for all of them -- not one
        // full ActivityDetail scan per category.
        ->and($queries)->toBeLessThanOrEqual(3);
});

it('reads a null progression signal when no distance has two efforts to compare', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 1500]);

    expect(new ProgressionSignalTool($user, Carbon::today(), app(ProgressionSeriesBuilder::class))->handle([])['progression_signal'])
        ->toBeNull();
});

// ── PersonaMixTool ───────────────────────────────────────────────────

// The full-window mix is folded from the two halves rather than asked for as a
// third overlapping group-by, so it has to stay exactly what a third query
// would have returned.
it('folds the full mood mix from its two halves', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::parse('2026-06-15 08:00:00');

    // LOOKBACK_WEEKS is 12, so the halfway mark is 6 weeks back.
    $seed = function (string $mood, Carbon $when) use ($user): void {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        $line = StoryLine::query()->create([
            'user_id' => $user->id, 'activity_id' => $activity->id,
            'kind' => StoryLine::KIND_POST_RUN, 'mood' => $mood,
            'speech' => null, 'sigil_pattern' => 'dddd',
        ]);
        // created_at is not fillable, so it has to be set after the insert.
        $line->created_at = $when;
        $line->save();
    };

    $seed('nyala', $asOf->copy()->subWeeks(2));
    $seed('nyala', $asOf->copy()->subWeeks(3));
    $seed('adem', $asOf->copy()->subWeeks(8));

    $reading = new PersonaMixTool($user, $asOf)->handle([]);

    expect($reading['total_runs'])->toBe(3)
        ->and($reading['persona_mix'])->toBe([
            ['mood' => 'nyala', 'count' => 2, 'percent' => 66.7],
            ['mood' => 'adem', 'count' => 1, 'percent' => 33.3],
        ])
        ->and($reading['persona_mix_recent'])->toBe([['mood' => 'nyala', 'count' => 2, 'percent' => 100.0]])
        ->and($reading['persona_mix_earlier'])->toBe([['mood' => 'adem', 'count' => 1, 'percent' => 100.0]]);
});

it('reads an empty mood mix when the runner has no story lines', function (): void {
    $user = User::factory()->create();

    $reading = new PersonaMixTool($user, Carbon::today())->handle([]);

    expect($reading['persona_mix'])->toBe([])
        ->and($reading['total_runs'])->toBe(0);
});
