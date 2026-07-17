<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use OpenAI\Resources\Responses;
use App\Services\AI\StructuredChatCaller;
use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\AkuProfileVoiceNarrator;
use App\Services\AI\Narrators\BriefingMascotVoiceNarrator;
use App\Services\AI\Narrators\CardFlavorNarrator;
use App\Services\AI\Narrators\DailyGreetingNarrator;
use App\Services\AI\Narrators\MonthlyRecapNarrator;
use App\Services\AI\Narrators\PersonaSummaryNarrator;
use App\Services\AI\Narrators\PostRunSpeechNarrator;
use App\Services\AI\Narrators\PrContextNarrator;
use App\Services\AI\Narrators\RunInsightNarrator;
use App\Services\AI\Narrators\TrendCaptionNarrator;
use App\Services\AI\Narrators\WeeklyRecapNarrator;
use App\Services\Run\LifetimeStats;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\Metrics\RunBaseline;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\ProgressionSeriesBuilder;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\PastYouMatcher;
use App\Services\Run\Story\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenAI\Testing\ClientFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/openai/deployments/x/chat/completions?api-version=2024-10-21');
    config()->set('azure_openai.api_key', 'fake-key');
    config()->set('azure_openai.deployment', 'x');
    config()->set('azure_openai.timeout', 8);
    config()->set('azure_openai.max_completion_tokens', 400);
});

function fakeCaller(string $content): StructuredChatCaller
{
    return capturingCaller($content)[0];
}

/**
 * Like {@see fakeCaller} but also returns the underlying ClientFake so a test can
 * assert on the exact request payload sent to Azure.
 *
 * @return array{0: StructuredChatCaller, 1: ClientFake}
 */
function capturingCaller(string $content): array
{
    $client = new ClientFake([fakeAzureResponse($content)]);

    return [fakeStructuredCaller($client), $client];
}

// ── PostRunSpeechNarrator ─────────────────────────────────────────────

/** @return array{technical: string, splits: string, zones: string} */
function postRunInsightsFixture(): array
{
    return [
        'technical' => 'Cadence 168, decoupling rendah.',
        'splits' => 'Km 4 tercepat, negative split rapi.',
        'zones' => '70% di Z2, cocok base building.',
    ];
}

function postRunFixture(): array
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

it('PostRunSpeechNarrator returns speech on valid JSON', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $caller = fakeCaller(json_encode(['speech' => 'Nice run today!'], JSON_THROW_ON_ERROR));
    $narrator = new PostRunSpeechNarrator($caller, app(PastYouMatcher::class));
    expect($narrator->generate($a, $d, 'nyala', postRunInsightsFixture()))->toBe('Nice run today!');
});

it('PostRunSpeechNarrator throws on non-JSON', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $caller = fakeCaller('not json');
    $narrator = new PostRunSpeechNarrator($caller, app(PastYouMatcher::class));
    $narrator->generate($a, $d, 'nyala', postRunInsightsFixture());
})->throws(UnavailableException::class, 'non-JSON');

it('PostRunSpeechNarrator throws on missing key', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $caller = fakeCaller(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new PostRunSpeechNarrator($caller, app(PastYouMatcher::class));
    $narrator->generate($a, $d, 'nyala', postRunInsightsFixture());
})->throws(UnavailableException::class, 'missing speech');

it('PostRunSpeechNarrator does not fatal when the stream summary is null', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => null]);
    $caller = fakeCaller(json_encode(['speech' => 'Mantap'], JSON_THROW_ON_ERROR));
    $narrator = new PostRunSpeechNarrator($caller, app(PastYouMatcher::class));
    expect($narrator->generate($a, $d->fresh(), 'dim', postRunInsightsFixture()))->toBe('Mantap');
});

it('PostRunSpeechNarrator resolves the dominant zone from a populated stream summary', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => [
        'time_in_zone_pct' => ['Z1' => 10, 'Z2' => 70, 'Z3' => 20],
        'decoupling_pct' => 5.2,
        'negative_split' => true,
    ]]);
    $caller = fakeCaller(json_encode(['speech' => 'Base solid'], JSON_THROW_ON_ERROR));
    $narrator = new PostRunSpeechNarrator($caller, app(PastYouMatcher::class));
    expect($narrator->generate($a, $d->fresh(), 'nyala', postRunInsightsFixture()))->toBe('Base solid');
});

it('PostRunSpeechNarrator carries the insight triplet into context', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $insights = postRunInsightsFixture();

    $context = (new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class)))->context($a, $d->fresh(), 'nyala', $insights);

    expect($context['insights'])->toBe($insights);
});

/**
 * Seed an earlier activity for $user with a Done analysis of $kind so the
 * per-activity continuity lookup has a predecessor to read.
 */
function priorActivityWithDoneAnalysis(User $user, AnalysisType $kind, string $content, string $startDate = '2026-05-09'): Activity
{
    $prior = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($prior)->create([
        'start_date_local' => Carbon::parse($startDate),
        'distance' => 4000.0,
        'moving_time' => 1200,
    ]);
    Analysis::factory()->done($content)->create([
        'subject_type' => Activity::class,
        'subject_id' => $prior->id,
        'analysis_type' => $kind,
        'discriminator' => null,
    ]);

    return $prior;
}

it('PostRunSpeechNarrator feeds prev_narrative from the prior activity post-run when Done', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    priorActivityWithDoneAnalysis($a->user, AnalysisType::PostRunSpeech, 'Lari kemarin enteng banget.');

    $context = (new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class)))->context($a, $d->fresh(), 'nyala', postRunInsightsFixture());

    expect($context['prev_narrative'])->toBe('Lari kemarin enteng banget.')
        // prev_opener is the first few words, so the model can steer away from it.
        ->and($context['prev_opener'])->toBe('Lari kemarin enteng banget.');
});

it('PostRunSpeechNarrator leaves prev_narrative null when there is no prior Done post-run', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    // A prior activity exists but its post-run is only Pending, so it is not a usable predecessor.
    $prior = Activity::factory()->for($a->user)->analyzed()->create();
    ActivityDetail::factory()->for($prior)->create(['start_date_local' => Carbon::parse('2026-05-09')]);
    Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $prior->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    $context = (new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class)))->context($a, $d->fresh(), 'nyala', postRunInsightsFixture());

    expect($context['prev_narrative'])->toBeNull()
        ->and($context['prev_opener'])->toBeNull();
});

it('PostRunSpeechNarrator truncates prev_opener to the first few words of a long prior narrative', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    priorActivityWithDoneAnalysis(
        $a->user,
        AnalysisType::PostRunSpeech,
        'Masih nyambung dari sesi kemarin, kali ini penutupmu lebih hidup dan pace makin rapi di akhir.',
    );

    $context = (new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class)))->context($a, $d->fresh(), 'nyala', postRunInsightsFixture());

    expect($context['prev_opener'])->toBe('Masih nyambung dari sesi kemarin, kali ini penutupmu lebih hidup')
        ->and(str_word_count((string) $context['prev_opener']))->toBeLessThanOrEqual(10);
});

it('PostRunSpeechNarrator feeds a past-you comparison when a comparable past run exists', function (): void {
    // Current run: 5km in 1500s (5:00/km, threshold band).
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['weather_temp_c' => null]); // don't let the random factory temp gate the match
    // A comparable run 30 days earlier: same distance band + threshold pace, but slower.
    $past = Activity::factory()->for($a->user)->analyzed()->create();
    ActivityDetail::factory()->for($past)->create([
        'start_date_local' => Carbon::today()->subDays(30),
        'distance' => 5000.0,
        'moving_time' => 1560, // 5:12/km, slower than the current 5:00/km
        'weather_temp_c' => null,
    ]);

    $context = (new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class)))
        ->context($a, $d->fresh(), 'nyala', postRunInsightsFixture());

    expect($context['past_you'])->not->toBeNull()
        ->and($context['past_you']['days_ago'])->toBe(30)
        ->and($context['past_you']['pace_diff_sec'])->toBeGreaterThan(0.0) // current is faster
        ->and($context['past_you']['past_km'])->toBe(5.0);
});

it('PostRunSpeechNarrator leaves past_you null when no comparable past run exists', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $context = (new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class)))
        ->context($a, $d->fresh(), 'nyala', postRunInsightsFixture());

    expect($context['past_you'])->toBeNull();
});

// ── DailyGreetingNarrator ─────────────────────────────────────────────

it('DailyGreetingNarrator returns speech on valid JSON', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller(json_encode(['speech' => 'Halo pagi'], JSON_THROW_ON_ERROR));
    $narrator = new DailyGreetingNarrator($caller);
    expect($narrator->generate($user, 'membara'))->toBe('Halo pagi');
});

it('DailyGreetingNarrator throws on missing speech key', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new DailyGreetingNarrator($caller);
    $narrator->generate($user, 'membara');
})->throws(UnavailableException::class);

it('DailyGreetingNarrator throws on non-JSON response', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller('not json');
    $narrator = new DailyGreetingNarrator($caller);
    $narrator->generate($user, 'membara');
})->throws(UnavailableException::class, 'non-JSON');

it('DailyGreetingNarrator feeds prev_narrative from the prior day greeting when Done', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('Halo, kemarin kamu fresh banget.')->create([
        'subject_type' => AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::DailyGreeting,
        'discriminator' => '2026-05-17',
    ]);

    $context = (new DailyGreetingNarrator(fakeCaller('{"speech":"x"}')))
        ->context($user, 'membara', Carbon::parse('2026-05-18'));

    expect($context['prev_narrative'])->toBe('Halo, kemarin kamu fresh banget.');
});

it('DailyGreetingNarrator omits prev_narrative when the prior day greeting is not yet Done', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::DailyGreeting,
        'discriminator' => '2026-05-17',
        'status' => AnalysisStatus::Pending,
    ]);

    $context = (new DailyGreetingNarrator(fakeCaller('{"speech":"x"}')))
        ->context($user, 'membara', Carbon::parse('2026-05-18'));

    expect($context['prev_narrative'])->toBeNull();
});

it('DailyGreetingNarrator leaves prev_narrative null on the first day', function (): void {
    $user = User::factory()->create();

    $context = (new DailyGreetingNarrator(fakeCaller('{"speech":"x"}')))
        ->context($user, 'membara', Carbon::parse('2026-05-18'));

    expect($context['prev_narrative'])->toBeNull();
});

// ── RunInsightNarrator ────────────────────────────────────────────────

it('RunInsightNarrator returns 3-string payload on valid JSON', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $caller = fakeCaller(json_encode([
        'technical' => 'tech text',
        'splits' => 'splits text',
        'zones' => 'zones text',
    ], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $payload = $narrator->generate($a, $d);
    expect($payload['technical'])->toBe('tech text')
        ->and($payload['splits'])->toBe('splits text')
        ->and($payload['zones'])->toBe('zones text');
});

it('RunInsightNarrator throws on missing keys', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $caller = fakeCaller(json_encode(['technical' => 'only one'], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $narrator->generate($a, $d);
})->throws(UnavailableException::class);

it('RunInsightNarrator throws on non-JSON', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $caller = fakeCaller('not json');
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $narrator->generate($a, $d);
})->throws(UnavailableException::class, 'non-JSON');

it('RunInsightNarrator does not fatal when the stream summary is null', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => null]);
    $caller = fakeCaller(json_encode([
        'technical' => 't', 'splits' => 's', 'zones' => 'z',
    ], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $payload = $narrator->generate($a, $d->fresh());
    expect($payload['zones'])->toBe('z');
});

it('RunInsightNarrator feeds training-load + pace-variability + zone-minutes into the context', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update([
        'trimp_edwards' => 92.4,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 70, 'Z3' => 30],
            'time_in_zone_min' => ['Z2' => 32, 'Z3' => 14],
            'pace_variability_sec' => 11.3,
            'ascent_m' => 48,
            'max_grade_pct' => 9.5,
            'gap_pace' => '5:40',
        ],
    ]);

    $narrator = new RunInsightNarrator(fakeCaller('{"technical":"t","splits":"s","zones":"z"}'), new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $context = $narrator->context($a, $d->fresh());

    expect($context['trimp'])->toBe(92.4)
        ->and($context['pace_variability_sec'])->toBe(11.3)
        ->and($context['time_in_zone_min'])->toBe(['Z2' => 32, 'Z3' => 14])
        ->and($context['ascent_m'])->toBe(48)
        ->and($context['max_grade_pct'])->toBe(9.5)
        ->and($context['gap_pace'])->toBe('5:40')
        // Single-run fixture: relative_effort carries the raw TRIMP with no
        // comparison (baseline too thin).
        ->and($context['relative_effort'])->toBe([
            'trimp' => 92.4, 'baseline' => null, 'ratio' => null, 'band' => null,
        ]);
});

it('RunInsightNarrator feeds a tagged workout_type into session_intent as workout/tagged', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update([
        'workout_type' => 3, // Strava "Workout"
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 90, 'Z3' => 10]],
    ]);

    $narrator = new RunInsightNarrator(fakeCaller('{"technical":"t","splits":"s","zones":"z"}'), new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $context = $narrator->context($a, $d->fresh());

    expect($context['session_intent'])->toBe(['intent' => 'workout', 'source' => 'tagged']);
});

it('RunInsightNarrator infers session_intent workout from a Z3-Z4 heavy untagged run', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update([
        'workout_type' => null,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 15, 'Z3' => 47, 'Z4' => 34, 'Z5' => 4]],
    ]);

    $narrator = new RunInsightNarrator(fakeCaller('{"technical":"t","splits":"s","zones":"z"}'), new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $context = $narrator->context($a, $d->fresh());

    expect($context['session_intent'])->toBe(['intent' => 'workout', 'source' => 'inferred']);
});

it('RunInsightNarrator prompt carries the quality-session framing so it stops assuming easy', function (): void {
    $prompt = narratorPrompt(RunInsightNarrator::class);

    expect($prompt)->toContain('session_intent')
        ->and($prompt)->toContain('SESI KUALITAS');
});

it('RunInsightNarrator prompt gives notes storytelling room (3-4 sentences, no rigid word cap)', function (): void {
    $prompt = narratorPrompt(RunInsightNarrator::class);

    expect($prompt)->toContain('3-4 kalimat')
        ->and($prompt)->toContain('jangan bertele-tele')
        ->and($prompt)->not->toContain('maksimal 55 kata');
});

it('RunInsightNarrator leaves the new context fields null when no stream summary', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => null, 'trimp_edwards' => null]);

    $narrator = new RunInsightNarrator(fakeCaller('{"technical":"t","splits":"s","zones":"z"}'), new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $context = $narrator->context($a, $d->fresh());

    expect($context['trimp'])->toBeNull()
        ->and($context['pace_variability_sec'])->toBeNull()
        ->and($context['time_in_zone_min'])->toBeNull();
});

it('RunInsightNarrator feeds the 28-day baseline + training load into the context', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    // A prior run 5 days earlier seeds the rolling baseline + TRIMP history.
    $prior = Activity::factory()->for($a->user)->analyzed()->create();
    ActivityDetail::factory()->for($prior)->create([
        'start_date_local' => Carbon::today()->subDays(5),
        'distance' => 10000.0,
        'moving_time' => 3600, // 6:00/km
        'average_heartrate' => 150.0,
        'trimp_edwards' => 80.0,
        'stream_summary' => ['decoupling_pct' => 6.0, 'time_in_zone_min' => ['Z2' => 40]],
    ]);

    $narrator = new RunInsightNarrator(fakeCaller('{"technical":"t","splits":"s","zones":"z"}'), new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $context = $narrator->context($a, $d->fresh());

    expect($context['recent_baseline_28d'])->toMatchArray([
        'runs' => 1,
        'avg_pace_sec_per_km' => 360,
        'avg_hr' => 150,
        'avg_decoupling_pct' => 6.0,
    ])
        ->and($context['training_load'])->not->toBeNull()
        ->and($context['training_load'])->toHaveKeys(['acute_7d', 'chronic_42d', 'form', 'form_status']);
});

it('RunInsightNarrator feeds prev_narrative from the prior activity technical insight when Done', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    priorActivityWithDoneAnalysis($a->user, AnalysisType::RunInsightTechnical, 'Cadence kemarin 168, mulai membaik.');

    $narrator = new RunInsightNarrator(fakeCaller('{"technical":"t","splits":"s","zones":"z"}'), new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $context = $narrator->context($a, $d->fresh());

    expect($context['prev_narrative'])->toBe('Cadence kemarin 168, mulai membaik.');
});

it('RunInsightNarrator leaves prev_narrative null when no prior technical insight is Done', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $narrator = new RunInsightNarrator(fakeCaller('{"technical":"t","splits":"s","zones":"z"}'), new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $context = $narrator->context($a, $d->fresh());

    expect($context['prev_narrative'])->toBeNull();
});

it('RunInsightNarrator feeds easy and threshold training paces derived from the runner VDOT', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    PersonalRecord::factory()->for($a->user)->create(['category' => '5km', 'value_sec' => 1200]);

    $narrator = new RunInsightNarrator(fakeCaller('{"technical":"t","splits":"s","zones":"z"}'), new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $context = $narrator->context($a, $d->fresh());

    expect($context['easy_pace_sec'])->toBeInt()
        ->and($context['threshold_pace_sec'])->toBeInt()
        ->and($context['easy_pace_sec'])->toBeGreaterThan($context['threshold_pace_sec']);
});

it('RunInsightNarrator leaves training paces null when the runner has no VDOT-eligible PR', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $narrator = new RunInsightNarrator(fakeCaller('{"technical":"t","splits":"s","zones":"z"}'), new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $context = $narrator->context($a, $d->fresh());

    expect($context['easy_pace_sec'])->toBeNull()
        ->and($context['threshold_pace_sec'])->toBeNull();
});

// ── WeeklyRecapNarrator ───────────────────────────────────────────────

it('WeeklyRecapNarrator returns narrative on valid JSON', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek()->toDateString(),
        'distance_km' => 30.0,
        'runs' => 4,
    ]);
    $caller = fakeCaller(json_encode(['narrative' => 'Minggu solid'], JSON_THROW_ON_ERROR));
    $narrator = new WeeklyRecapNarrator($caller);
    expect($narrator->generate($snap))->toBe('Minggu solid');
});

it('WeeklyRecapNarrator throws on missing narrative key', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek()->toDateString(),
    ]);
    $caller = fakeCaller(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new WeeklyRecapNarrator($caller);
    $narrator->generate($snap);
})->throws(UnavailableException::class);

it('WeeklyRecapNarrator throws on non-JSON', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek()->toDateString(),
    ]);
    $caller = fakeCaller('not json');
    $narrator = new WeeklyRecapNarrator($caller);
    $narrator->generate($snap);
})->throws(UnavailableException::class, 'non-JSON');

it('WeeklyRecapNarrator feeds the previous week deltas when a prior snapshot exists', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-10', 'distance_km' => 20.0, 'runs' => 3, 'moving_time_sec' => 7200,
    ]);
    $current = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17', 'distance_km' => 28.0, 'runs' => 4, 'moving_time_sec' => 9600,
    ]);

    $context = (new WeeklyRecapNarrator(fakeCaller('{"narrative":"x"}')))->context($current);

    expect($context['prev_distance_km'])->toBe(20.0)
        ->and($context['prev_runs'])->toBe(3)
        ->and($context['prev_pace_sec_per_km'])->not->toBeNull();
});

it('WeeklyRecapNarrator leaves previous-week deltas null on the first week', function (): void {
    $user = User::factory()->create();
    $current = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);

    $context = (new WeeklyRecapNarrator(fakeCaller('{"narrative":"x"}')))->context($current);

    expect($context['prev_distance_km'])->toBeNull()
        ->and($context['prev_runs'])->toBeNull()
        ->and($context['prev_pace_sec_per_km'])->toBeNull()
        ->and($context['prev_narrative'])->toBeNull();
});

it('WeeklyRecapNarrator feeds prev_narrative when the prior week recap is Done', function (): void {
    $user = User::factory()->create();
    $prior = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10']);
    Analysis::factory()->done('Minggu lalu kamu solid.')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $prior->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);
    $current = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);

    $context = (new WeeklyRecapNarrator(fakeCaller('{"narrative":"x"}')))->context($current);

    expect($context['prev_narrative'])->toBe('Minggu lalu kamu solid.');
});

it('WeeklyRecapNarrator omits prev_narrative when the prior week recap is not yet Done', function (): void {
    $user = User::factory()->create();
    $prior = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10']);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $prior->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);
    $current = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);

    $context = (new WeeklyRecapNarrator(fakeCaller('{"narrative":"x"}')))->context($current);

    expect($context['prev_narrative'])->toBeNull();
});

it('WeeklyRecapNarrator feeds avg_decoupling into the context', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17', 'avg_decoupling' => 6.4,
    ]);

    $context = (new WeeklyRecapNarrator(fakeCaller('{"narrative":"x"}')))->context($snap);

    expect($context['avg_decoupling'])->toBe(6.4);
});

// ── PrContextNarrator ─────────────────────────────────────────────────

it('PrContextNarrator returns flavor on valid JSON', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1500,
    ]);
    $caller = fakeCaller(json_encode(['flavor' => 'PR baru!'], JSON_THROW_ON_ERROR));
    $narrator = new PrContextNarrator($caller, app(VdotEstimator::class));
    expect($narrator->generate($pr))->toBe('PR baru!');
});

it('PrContextNarrator throws on missing flavor key', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->for($user)->create();
    $caller = fakeCaller(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new PrContextNarrator($caller, app(VdotEstimator::class));
    $narrator->generate($pr);
})->throws(UnavailableException::class);

it('PrContextNarrator throws on non-JSON', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->for($user)->create();
    $caller = fakeCaller('not json');
    $narrator = new PrContextNarrator($caller, app(VdotEstimator::class));
    $narrator->generate($pr);
})->throws(UnavailableException::class, 'non-JSON');

it('PrContextNarrator flags the PR category as the strongest event when it drives the best VDOT', function (): void {
    $user = User::factory()->create();
    // A single 5km PR is, by construction, the user's best-VDOT source category.
    $pr = PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 1200]);

    $context = (new PrContextNarrator(fakeCaller('{"flavor":"x"}'), app(VdotEstimator::class)))->context($pr);

    expect($context['is_strongest_event'])->toBeTrue()
        ->and($context['vdot'])->not->toBeNull();
});

it('PrContextNarrator feeds the PR run conditions into the context', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['weather_temp_c' => 33]);
    $pr = PersonalRecord::factory()->for($user)->create([
        'category' => '5km', 'value_sec' => 1500, 'activity_id' => $activity->id,
    ]);

    $context = (new PrContextNarrator(fakeCaller('{"flavor":"x"}'), app(VdotEstimator::class)))->context($pr);

    expect($context['weather_temp_c'])->toBe(33);
});

// ── TrendCaptionNarrator ──────────────────────────────────────────────

it('TrendCaptionNarrator returns caption on valid JSON', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->subWeek()->endOfWeek()->toDateString(),
        'distance_km' => 25,
        'ctl_42d' => 40,
    ]);
    $caller = fakeCaller(json_encode(['caption' => 'Tren naik'], JSON_THROW_ON_ERROR));
    $narrator = new TrendCaptionNarrator($caller, app(TrainingLoad::class));
    expect($narrator->generate($user, Carbon::today()))->toBe('Tren naik');
});

it('TrendCaptionNarrator throws on missing caption key', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new TrendCaptionNarrator($caller, app(TrainingLoad::class));
    $narrator->generate($user, Carbon::today());
})->throws(UnavailableException::class);

it('TrendCaptionNarrator throws on non-JSON', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller('not json');
    $narrator = new TrendCaptionNarrator($caller, app(TrainingLoad::class));
    $narrator->generate($user, Carbon::today());
})->throws(UnavailableException::class, 'non-JSON');

it('TrendCaptionNarrator derives the 4-week CTL + volume deltas', function (): void {
    $user = User::factory()->create();
    // 8 weeks of data: CTL climbs 30 -> 44, volume recent 4w sum vs prior 4w sum.
    $ctls = [30, 32, 34, 36, 38, 40, 42, 44];
    $kms = [10, 10, 10, 10, 12, 12, 12, 12];
    foreach ($ctls as $i => $ctl) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::parse('2026-03-08')->addWeeks($i)->toDateString(),
            'distance_km' => $kms[$i],
            'ctl_42d' => $ctl,
        ]);
    }

    $context = (new TrendCaptionNarrator(fakeCaller('{"caption":"x"}'), app(TrainingLoad::class)))
        ->context($user, Carbon::parse('2026-05-01'));

    // CTL: latest 44 minus the one 4 weeks earlier (36) = 8.0.
    expect($context['ctl_delta_4w'])->toBe(8.0)
        ->and($context['volume_recent_4w_km'])->toBe(48.0)  // 12*4
        ->and($context['volume_prev_4w_km'])->toBe(40.0);   // 10*4
});

it('TrendCaptionNarrator flags weeks that contain a personal record', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10', 'distance_km' => 20, 'ctl_42d' => 30]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'distance_km' => 25, 'ctl_42d' => 33]);
    // A PR set on Thu 2026-05-14 falls in the week ending Sun 2026-05-17.
    PersonalRecord::factory()->for($user)->create(['set_at' => Carbon::parse('2026-05-14T06:00')]);

    $context = (new TrendCaptionNarrator(fakeCaller('{"caption":"x"}'), app(TrainingLoad::class)))
        ->context($user, Carbon::parse('2026-05-18'));

    $weeks = collect($context['weeks']);
    expect($weeks->firstWhere('ending', '2026-05-17')['pr'])->toBeTrue()
        ->and($weeks->firstWhere('ending', '2026-05-10')['pr'])->toBeFalse();
});

it('TrendCaptionNarrator leaves the 4-week deltas null without enough history', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-03', 'distance_km' => 12, 'ctl_42d' => 30,
    ]);

    $context = (new TrendCaptionNarrator(fakeCaller('{"caption":"x"}'), app(TrainingLoad::class)))
        ->context($user, Carbon::parse('2026-05-04'));

    expect($context['ctl_delta_4w'])->toBeNull()
        ->and($context['volume_recent_4w_km'])->toBeNull();
});

// ── CardFlavorNarrator ────────────────────────────────────────────────

function cardFixture(): RunCard
{
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 5000.0,
        'moving_time' => 1500,
    ]);

    return RunCard::factory()->create([
        'activity_id' => $activity->id,
        'rarity' => 'rare',
        'special_move' => 'Pembara Sabar',
    ]);
}

it('CardFlavorNarrator returns flavor on valid JSON', function (): void {
    $card = cardFixture();
    $caller = fakeCaller(json_encode(['flavor' => 'Kartu epic!'], JSON_THROW_ON_ERROR));
    $narrator = new CardFlavorNarrator($caller);
    expect($narrator->generate($card))->toBe('Kartu epic!');
});

it('CardFlavorNarrator throws on missing flavor key', function (): void {
    $card = cardFixture();
    $caller = fakeCaller(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new CardFlavorNarrator($caller);
    $narrator->generate($card);
})->throws(UnavailableException::class);

it('CardFlavorNarrator throws on non-JSON', function (): void {
    $card = cardFixture();
    $caller = fakeCaller('not json');
    $narrator = new CardFlavorNarrator($caller);
    $narrator->generate($card);
})->throws(UnavailableException::class, 'non-JSON');

it('CardFlavorNarrator humanizes badge slugs so no raw code reaches the prompt', function (): void {
    $card = cardFixture();
    // long_slow_distance (not negative_split) as the example slug: negative_split
    // is now a legitimate pacing context key, so it appears in the payload by design.
    $card->update(['badges' => ['long_slow_distance', 'pejuang_hujan', 'not_a_real_badge']]);

    [$caller, $client] = capturingCaller(json_encode(['flavor' => 'ok'], JSON_THROW_ON_ERROR));
    (new CardFlavorNarrator($caller))->generate($card->fresh());

    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $payload = json_encode($params, JSON_THROW_ON_ERROR);

        return str_contains($payload, 'Long Slow Distance')
            && str_contains($payload, 'Pejuang Hujan')
            && ! str_contains($payload, 'long_slow_distance')
            && ! str_contains($payload, 'pejuang_hujan')
            && ! str_contains($payload, 'not_a_real_badge');
    });
});

it('CardFlavorNarrator feeds decoupling + negative split pacing into the payload', function (): void {
    $card = cardFixture();
    $card->loadMissing('activity.detail');
    $card->activity->detail->update(['stream_summary' => ['decoupling_pct' => 4.5, 'negative_split' => true]]);

    [$caller, $client] = capturingCaller(json_encode(['flavor' => 'ok'], JSON_THROW_ON_ERROR));
    (new CardFlavorNarrator($caller))->generate($card->fresh());

    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $payload = json_encode($params, JSON_THROW_ON_ERROR);

        return str_contains($payload, 'decoupling_pct')
            && str_contains($payload, '4.5')
            && str_contains($payload, 'negative_split');
    });
});

// ── PersonaSummaryNarrator ────────────────────────────────────────────

it('PersonaSummaryNarrator builds a mood-mix percent breakdown from story lines', function (): void {
    $user = User::factory()->create();
    $cutoff = Carbon::now()->subWeeks(11);

    foreach (['nyala', 'nyala', 'nyala', 'adem', 'lemes'] as $mood) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        StoryLine::factory()->for($user)->create([
            'activity_id' => $activity->id,
            'mood' => $mood,
            'created_at' => $cutoff->copy()->addDay(),
        ]);
    }

    $caller = fakeCaller(json_encode(['narrative' => 'Larimu lebih sering nyala.'], JSON_THROW_ON_ERROR));
    $narrator = new PersonaSummaryNarrator($caller);

    $mix = $narrator->personaMix($user->fresh());
    $nyala = collect($mix)->firstWhere('mood', 'nyala');
    expect($nyala['mood'])->toBe('nyala');
    expect($nyala['count'])->toBe(3);
    expect($nyala['percent'])->toBe(60.0);
    expect($narrator->generate($user->fresh()))->toBe('Larimu lebih sering nyala.');
});

it('PersonaSummaryNarrator feeds the latest form_status as the consistency spine', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'form_status' => 'fatigued']);

    $context = (new PersonaSummaryNarrator(fakeCaller('{"narrative":"x"}')))->context($user->fresh());

    expect($context['form_status'])->toBe('fatigued');
});

it('PersonaSummaryNarrator returns an empty mix for a user with no story lines', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller(json_encode(['narrative' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new PersonaSummaryNarrator($caller);
    expect($narrator->personaMix($user))->toBe([]);
});

it('PersonaSummaryNarrator splits the persona mix into recent vs earlier halves', function (): void {
    $user = User::factory()->create();
    // Earlier half (8 weeks ago): adem-dominant. Recent half (1 week ago): nyala-dominant.
    $seed = function (string $mood, int $weeksAgo) use ($user): void {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        StoryLine::factory()->for($user)->create([
            'activity_id' => $activity->id,
            'mood' => $mood,
            'created_at' => Carbon::now()->subWeeks($weeksAgo),
        ]);
    };
    $seed('adem', 8);
    $seed('adem', 8);
    $seed('nyala', 1);

    $context = (new PersonaSummaryNarrator(fakeCaller('{"narrative":"x"}')))->context($user->fresh());

    expect($context['persona_mix_earlier'][0]['mood'])->toBe('adem')
        ->and($context['persona_mix_recent'][0]['mood'])->toBe('nyala')
        ->and($context['total_runs'])->toBe(3);
});

// ── MonthlyRecapNarrator ──────────────────────────────────────────────

it('MonthlyRecapNarrator pulls month totals + mood mix into the context payload', function (): void {
    $user = User::factory()->create();
    $month = '2026-05';

    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8000.0,
        'start_date_local' => Carbon::parse('2026-05-12T07:00'),
    ]);
    StoryLine::factory()->for($user)->create([
        'activity_id' => $activity->id,
        'mood' => 'nyala',
        'created_at' => Carbon::parse('2026-05-12T08:00'),
    ]);

    $caller = fakeCaller(json_encode(['narrative' => 'Bulan ini mostly nyala.'], JSON_THROW_ON_ERROR));
    $narrator = new MonthlyRecapNarrator($caller);

    $context = $narrator->context($user, $month);
    expect($context['month'])->toBe('2026-05');
    expect($context['total_runs'])->toBe(1);
    expect($context['total_distance_km'])->toBe(8.0);
    expect($context['longest_run_km'])->toBe(8.0);
    expect($context['mood_mix'][0]['mood'])->toBe('nyala');
    expect($context['pr_count'])->toBe(0);
    expect($context['weekly_distance_km'])->toBeArray();
    expect($narrator->generate($user, $month))->toBe('Bulan ini mostly nyala.');
});

it('MonthlyRecapNarrator counts PRs and buckets distance by week within the month', function (): void {
    $user = User::factory()->create();
    $month = '2026-05';

    // Week 1 (May 1-7): one 6km run. Week 3 (May 15-21): one 10km run.
    foreach ([['2026-05-03', 6000.0], ['2026-05-19', 10000.0]] as [$date, $meters]) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::parse($date . 'T06:00'),
            'distance' => $meters,
        ]);
    }
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km', 'set_at' => Carbon::parse('2026-05-19T06:30'),
    ]);

    $context = (new MonthlyRecapNarrator(fakeCaller('{"narrative":"x"}')))->context($user, $month);

    expect($context['pr_count'])->toBe(1)
        ->and($context['weekly_distance_km'][0])->toBe(6.0)
        ->and($context['weekly_distance_km'][2])->toBe(10.0);
});

it('MonthlyRecapNarrator reads the CTL fitness arc from the month snapshots', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-03', 'ctl_42d' => 30.0, 'form_status' => 'optimal',
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-31', 'ctl_42d' => 38.0, 'form_status' => 'fresh',
    ]);

    $context = (new MonthlyRecapNarrator(fakeCaller('{"narrative":"x"}')))->context($user, '2026-05');

    expect($context['fitness'])->toMatchArray([
        'ctl_start' => 30.0,
        'ctl_end' => 38.0,
        'form_status_end' => 'fresh',
    ]);
});

it('MonthlyRecapNarrator leaves fitness null when the month has no snapshots', function (): void {
    $user = User::factory()->create();

    $context = (new MonthlyRecapNarrator(fakeCaller('{"narrative":"x"}')))->context($user, '2026-05');

    expect($context['fitness'])->toBeNull();
});

it('MonthlyRecapNarrator feeds prev_narrative when the prior month recap is Done', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('Bulan lalu kamu konsisten.')->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-04',
    ]);

    $context = (new MonthlyRecapNarrator(fakeCaller('{"narrative":"x"}')))->context($user, '2026-05');

    expect($context['prev_narrative'])->toBe('Bulan lalu kamu konsisten.');
});

it('MonthlyRecapNarrator omits prev_narrative when the prior month recap is not yet Done', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-04',
        'status' => AnalysisStatus::Pending,
    ]);

    $context = (new MonthlyRecapNarrator(fakeCaller('{"narrative":"x"}')))->context($user, '2026-05');

    expect($context['prev_narrative'])->toBeNull();
});

it('MonthlyRecapNarrator leaves prev_narrative null on the first month', function (): void {
    $user = User::factory()->create();

    $context = (new MonthlyRecapNarrator(fakeCaller('{"narrative":"x"}')))->context($user, '2026-05');

    expect($context['prev_narrative'])->toBeNull();
});

// ── AkuProfileVoiceNarrator ───────────────────────────────────────────

it('AkuProfileVoiceNarrator returns profile voice on valid JSON', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller(json_encode(['profile_voice' => 'Kamu udah lari 50 km, keren.'], JSON_THROW_ON_ERROR));
    $narrator = new AkuProfileVoiceNarrator($caller, app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));
    expect($narrator->generate($user))->toBe('Kamu udah lari 50 km, keren.');
});

it('AkuProfileVoiceNarrator builds context from user stats', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000.0,
        'start_date_local' => Carbon::parse('2026-05-12T07:00'),
    ]);

    $caller = fakeCaller(json_encode(['profile_voice' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new AkuProfileVoiceNarrator($caller, app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));

    $context = $narrator->context($user->fresh());
    expect($context['total_runs'])->toBe(1)
        ->and($context['total_km'])->toBe(5.0)
        ->and($context['longest_run_km'])->toBe(5.0)
        // 07:00 falls in the pagi bucket; streak needs no snapshots so 0.
        ->and($context['favorite_time'])->toBe('pagi')
        ->and($context['weekly_streak'])->toBe(0);
});

it('AkuProfileVoiceNarrator reads the weekly streak and the most common run time', function (): void {
    $user = User::factory()->create();
    // Two consecutive weeks with runs -> streak 2.
    foreach ([0, 1] as $weeksBack) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->subWeeks($weeksBack)->toDateString(),
            'runs' => 3,
        ]);
    }
    // Most runs in the evening (malam).
    foreach (['2026-05-10T20:00', '2026-05-12T21:00', '2026-05-14T07:00'] as $when) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => 5000.0,
            'start_date_local' => Carbon::parse($when),
        ]);
    }

    $context = (new AkuProfileVoiceNarrator(fakeCaller('{"profile_voice":"x"}'), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class)))->context($user->fresh());

    expect($context['weekly_streak'])->toBe(2)
        ->and($context['favorite_time'])->toBe('malam');
});

it('AkuProfileVoiceNarrator feeds the latest form_status as the consistency spine', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'runs' => 3, 'form_status' => 'overreaching',
    ]);

    $context = (new AkuProfileVoiceNarrator(fakeCaller('{"profile_voice":"x"}'), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class)))
        ->context($user->fresh());

    expect($context['form_status'])->toBe('overreaching');
});

it('AkuProfileVoiceNarrator throws on missing profile_voice key', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new AkuProfileVoiceNarrator($caller, app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));
    $narrator->generate($user);
})->throws(UnavailableException::class);

it('AkuProfileVoiceNarrator throws on non-JSON', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller('not json');
    $narrator = new AkuProfileVoiceNarrator($caller, app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));
    $narrator->generate($user);
})->throws(UnavailableException::class, 'non-JSON');

it('AkuProfileVoiceNarrator feeds the four training paces derived from the runner VDOT', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 1200]);

    $context = (new AkuProfileVoiceNarrator(fakeCaller('{"profile_voice":"x"}'), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class)))
        ->context($user->fresh());

    expect($context['easy_pace_sec'])->toBeInt()
        ->and($context['marathon_pace_sec'])->toBeInt()
        ->and($context['threshold_pace_sec'])->toBeInt()
        ->and($context['interval_pace_sec'])->toBeInt();
});

it('AkuProfileVoiceNarrator leaves training paces null when the user has no VDOT-eligible PR', function (): void {
    $user = User::factory()->create();

    $context = (new AkuProfileVoiceNarrator(fakeCaller('{"profile_voice":"x"}'), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class)))
        ->context($user->fresh());

    expect($context['easy_pace_sec'])->toBeNull()
        ->and($context['marathon_pace_sec'])->toBeNull()
        ->and($context['threshold_pace_sec'])->toBeNull()
        ->and($context['interval_pace_sec'])->toBeNull();
});

// ── BriefingMascotVoiceNarrator ───────────────────────────────────────

function bootMascotNarrator(string $content): BriefingMascotVoiceNarrator
{
    return new BriefingMascotVoiceNarrator(
        app(Vibe::class),
        app(TrainingLoad::class),
        app(VerdictNarrator::class),
        fakeCaller($content),
        app(PastYouMatcher::class),
    );
}

it('BriefingMascotVoiceNarrator returns the mascot voice on valid JSON', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->analyzed()->create();

    $narrator = bootMascotNarrator(json_encode(['mascot_voice' => 'Aku liat km kamu naik tipis, bagus.'], JSON_THROW_ON_ERROR));

    expect($narrator->generate($user, Carbon::today()))->toBe('Aku liat km kamu naik tipis, bagus.');
});

it('BriefingMascotVoiceNarrator throws on missing mascot_voice key', function (): void {
    $user = User::factory()->create();
    $narrator = bootMascotNarrator(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator->generate($user, Carbon::today());
})->throws(UnavailableException::class, 'missing mascot_voice');

it('BriefingMascotVoiceNarrator throws on non-JSON', function (): void {
    $user = User::factory()->create();
    $narrator = bootMascotNarrator('not json');
    $narrator->generate($user, Carbon::today());
})->throws(UnavailableException::class, 'non-JSON');

it('BriefingMascotVoiceNarrator feeds prev_narrative from the prior day Kata Temari when Done', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('Kemarin aku liat km kamu naik.')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-17',
    ]);

    $context = bootMascotNarrator('{"mascot_voice":"x"}')->context($user, Carbon::parse('2026-05-18'));

    expect($context['prev_narrative'])->toBe('Kemarin aku liat km kamu naik.');
});

it('BriefingMascotVoiceNarrator omits prev_narrative when the prior day Kata Temari is not yet Done', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-17',
        'status' => AnalysisStatus::Pending,
    ]);

    $context = bootMascotNarrator('{"mascot_voice":"x"}')->context($user, Carbon::parse('2026-05-18'));

    expect($context['prev_narrative'])->toBeNull();
});

it('BriefingMascotVoiceNarrator leaves prev_narrative null on the first day', function (): void {
    $user = User::factory()->create();

    $context = bootMascotNarrator('{"mascot_voice":"x"}')->context($user, Carbon::parse('2026-05-18'));

    expect($context['prev_narrative'])->toBeNull();
});

it('BriefingMascotVoiceNarrator feeds a past-you comparison for the latest run', function (): void {
    $user = User::factory()->create();
    // Latest run: 5km in 1500s (5:00/km, threshold band), today.
    $recent = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($recent)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 5000.0, 'moving_time' => 1500, 'weather_temp_c' => null,
    ]);
    // A comparable run 30 days earlier, slower.
    $past = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($past)->create([
        'start_date_local' => Carbon::today()->subDays(30),
        'distance' => 5000.0, 'moving_time' => 1560, 'weather_temp_c' => null,
    ]);

    $context = bootMascotNarrator('{"mascot_voice":"x"}')->context($user, Carbon::today());

    expect($context['past_you'])->not->toBeNull()
        ->and($context['past_you']['days_ago'])->toBe(30)
        ->and($context['past_you']['pace_diff_sec'])->toBeGreaterThan(0.0);
});

it('BriefingMascotVoiceNarrator leaves past_you null without a comparable past run', function (): void {
    $user = User::factory()->create();
    $only = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($only)->create([
        'start_date_local' => Carbon::today(), 'distance' => 5000.0, 'moving_time' => 1500,
    ]);

    $context = bootMascotNarrator('{"mascot_voice":"x"}')->context($user, Carbon::today());

    expect($context['past_you'])->toBeNull();
});

// ── Prompt wording guards (slice 8 polish) ────────────────────────────

/** Read a narrator's private SYSTEM_PROMPT constant for wording assertions. */
function narratorPrompt(string $class): string
{
    return (string) (new ReflectionClass($class))->getConstant('SYSTEM_PROMPT');
}

it('MonthlyRecapNarrator prompt makes the mood step conditional on mood_mix', function (): void {
    $prompt = narratorPrompt(MonthlyRecapNarrator::class);

    expect($prompt)
        ->toContain('HANYA kalau mood_mix terisi')
        ->toContain('LEWATI langkah ini diam-diam')
        ->not->toContain('—');
});

it('recap prompts give storytelling room (3-4 sentences, no rigid word cap)', function (string $narrator): void {
    $prompt = narratorPrompt($narrator);

    expect($prompt)->toContain('3-4 kalimat')
        ->and($prompt)->toContain('jangan bertele-tele')
        ->and($prompt)->not->toContain('maksimal 90 kata')
        ->and($prompt)->not->toContain('maksimal 100 kata');
})->with([
    'weekly' => [WeeklyRecapNarrator::class],
    'monthly' => [MonthlyRecapNarrator::class],
]);

it('TrendCaptionNarrator prompt demands one coherent reading with a concrete number', function (): void {
    $prompt = narratorPrompt(TrendCaptionNarrator::class);

    expect($prompt)
        ->toContain('SATU PEMBACAAN SAJA')
        ->toContain('jangan')
        ->toContain('minimal 1 angka konkret')
        ->not->toContain('—');
});

it('RunInsightNarrator prompt steers general words to Indonesian while keeping run terms English', function (): void {
    $prompt = narratorPrompt(RunInsightNarrator::class);

    expect($prompt)
        ->toContain('BAHASA:')
        ->toContain('stabil/rata bukan "steady"')
        ->toContain('negative split')
        ->not->toContain('—');
});
