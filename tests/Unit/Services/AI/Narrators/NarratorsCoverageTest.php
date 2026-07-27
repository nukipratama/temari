<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
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
use App\Services\AI\Agent\Tools\LifetimeStatsTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\AI\Agent\Tools\MonthTotalsTool;
use App\Services\AI\Agent\Tools\PersonaMixTool;
use App\Services\AI\Agent\Tools\PersonalRecordTool;
use App\Services\AI\Agent\Tools\TrainingPacesTool;
use App\Services\AI\Agent\Tools\WeekTotalsTool;
use App\Services\AI\Agent\Tools\WeeklyTrendTool;
use App\Services\AI\Narrators\AkuProfileVoiceNarrator;
use App\Services\AI\Narrators\BriefingMascotVoiceNarrator;
use App\Services\AI\Narrators\BriefingNarrator;
use App\Services\AI\Narrators\CardFlavorNarrator;
use App\Services\AI\Narrators\DailyGreetingNarrator;
use App\Services\AI\Narrators\NarratorContinuity;
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

it('PostRunSpeechNarrator narrates a run with a populated stream summary', function (): void {
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

    $context = new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class))->context($a, $d->fresh(), 'nyala', $insights);

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

    $context = new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class))->context($a, $d->fresh(), 'nyala', postRunInsightsFixture());

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

    $context = new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class))->context($a, $d->fresh(), 'nyala', postRunInsightsFixture());

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

    $context = new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class))->context($a, $d->fresh(), 'nyala', postRunInsightsFixture());

    expect($context['prev_opener'])->toBe('Masih nyambung dari sesi kemarin, kali ini penutupmu lebih hidup')
        ->and(str_word_count((string) $context['prev_opener']))->toBeLessThanOrEqual(10);
});

it('PostRunSpeechNarrator keeps only what no tool can serve in the context', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $context = new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class))
        ->context($a, $d->fresh(), 'nyala', postRunInsightsFixture());

    // mood is the call's own argument and the insights were written moments ago
    // in this same job, so neither is readable from anywhere.
    expect(array_keys($context))
        ->toBe(['mood', 'insights', ...NarratorContinuity::CONTEXT_KEYS]);
});

it('PostRunSpeechNarrator offers the run reads plus the two its story needs', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $names = array_column(
        new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class))
            ->toolbox($a, $d)->definitions(),
        'name',
    );

    expect($names)->toBe([
        'get_run_summary',
        'get_km_splits',
        'get_hr_zones',
        'get_terrain',
        'get_weather',
        'get_personal_records',
        'get_past_you',
    ]);
});

// ── DailyGreetingNarrator ─────────────────────────────────────────────

it('DailyGreetingNarrator returns speech on valid JSON', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller(json_encode(['speech' => 'Halo pagi'], JSON_THROW_ON_ERROR));
    $narrator = new DailyGreetingNarrator($caller, new TrainingLoad(), app(VerdictNarrator::class));
    expect($narrator->generate($user, 'membara'))->toBe('Halo pagi');
});

it('DailyGreetingNarrator throws on missing speech key', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new DailyGreetingNarrator($caller, new TrainingLoad(), app(VerdictNarrator::class));
    $narrator->generate($user, 'membara');
})->throws(UnavailableException::class);

it('DailyGreetingNarrator throws on non-JSON response', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller('not json');
    $narrator = new DailyGreetingNarrator($caller, new TrainingLoad(), app(VerdictNarrator::class));
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

    $context = new DailyGreetingNarrator(fakeCaller('{"speech":"x"}'), new TrainingLoad(), app(VerdictNarrator::class))
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

    $context = new DailyGreetingNarrator(fakeCaller('{"speech":"x"}'), new TrainingLoad(), app(VerdictNarrator::class))
        ->context($user, 'membara', Carbon::parse('2026-05-18'));

    expect($context['prev_narrative'])->toBeNull();
});

it('DailyGreetingNarrator leaves prev_narrative null on the first day', function (): void {
    $user = User::factory()->create();

    $context = new DailyGreetingNarrator(fakeCaller('{"speech":"x"}'), new TrainingLoad(), app(VerdictNarrator::class))
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

it('RunInsightNarrator sends no run data in the context, only the continuity the filter retry must be able to strip', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $narrator = new RunInsightNarrator(fakeCaller('{"technical":"t","splits":"s","zones":"z"}'), new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));

    expect(array_keys($narrator->context($a, $d->fresh())))
        ->toBe(NarratorContinuity::CONTEXT_KEYS);
});

it('RunInsightNarrator offers every run reading as a tool bound to this activity', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $narrator = new RunInsightNarrator(fakeCaller('{"technical":"t","splits":"s","zones":"z"}'), new TrainingLoad(), new RunBaseline(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $names = array_column($narrator->toolbox($a, $d)->definitions(), 'name');

    expect($names)->toBe([
        'get_run_summary',
        'get_km_splits',
        'get_hr_zones',
        'get_terrain',
        'get_weather',
        'get_effort_context',
        'get_training_load',
        'get_recent_baseline',
        'get_training_paces',
    ]);
});

it('RunInsightNarrator prompt tells the model to fetch its own numbers and not invent the rest', function (): void {
    $prompt = narratorPrompt(RunInsightNarrator::class);

    expect($prompt)->toContain('Ambil sendiri lewat tool')
        ->and($prompt)->toContain('JANGAN dikarang');
});

it('BriefingNarrator sends only the addressee and the day, and reads the rest', function (): void {
    $user = User::factory()->create();
    $narrator = app(BriefingNarrator::class);

    expect(array_keys($narrator->context($user, Carbon::today())))
        ->toBe(['name', 'vibe', 'date'])
        ->and(array_column($narrator->toolbox($user, Carbon::today())->definitions(), 'name'))
        ->toBe(['get_week_state', 'get_recent_runs', 'get_training_load', 'get_recent_baseline']);
});

it('BriefingMascotVoiceNarrator reads the day and can compare against the last run', function (): void {
    $user = User::factory()->create();
    $narrator = app(BriefingMascotVoiceNarrator::class);

    expect(array_column($narrator->toolbox($user, Carbon::today())->definitions(), 'name'))
        ->toBe(['get_week_state', 'get_recent_runs', 'get_training_load', 'get_latest_past_you']);
});

it('DailyGreetingNarrator gains the gap it could never see from the vibe alone', function (): void {
    $user = User::factory()->create();
    $narrator = app(DailyGreetingNarrator::class);

    // The vibe stays in the context because the caller decided it; a tool that
    // recomputed it would be a second source of truth.
    expect(array_keys($narrator->context($user, 'membara', Carbon::today())))
        ->toBe(['name', 'vibe', 'vibe_label', ...NarratorContinuity::CONTEXT_KEYS])
        ->and(array_column($narrator->toolbox($user, Carbon::today())->definitions(), 'name'))
        ->toBe(['get_week_state', 'get_recent_runs']);
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

it('WeekTotalsTool reads the previous week deltas when a prior snapshot exists', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-10', 'distance_km' => 20.0, 'runs' => 3, 'moving_time_sec' => 7200,
    ]);
    $current = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17', 'distance_km' => 28.0, 'runs' => 4, 'moving_time_sec' => 9600,
    ]);

    $context = new WeekTotalsTool($current)->handle([]);

    expect($context['prev_distance_km'])->toBe(20.0)
        ->and($context['prev_runs'])->toBe(3)
        ->and($context['prev_pace_sec_per_km'])->not->toBeNull();
});

it('WeeklyRecapNarrator leaves previous-week deltas null on the first week', function (): void {
    $user = User::factory()->create();
    $current = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);

    $context = new WeekTotalsTool($current)->handle([]);

    expect($context['prev_distance_km'])->toBeNull()
        ->and($context['prev_runs'])->toBeNull()
        ->and($context['prev_pace_sec_per_km'])->toBeNull();
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

    $context = new WeeklyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($current);

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

    $context = new WeeklyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($current);

    expect($context['prev_narrative'])->toBeNull();
});

it('WeekTotalsTool reads avg_decoupling for the week', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17', 'avg_decoupling' => 6.4,
    ]);

    $context = new WeekTotalsTool($snap)->handle([]);

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

    $context = new PersonalRecordTool($pr, app(VdotEstimator::class))->handle([]);

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

    $context = new WeatherTool($activity, $activity->detail)->handle([]);

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

it('WeeklyTrendTool derives the 4-week CTL + volume deltas', function (): void {
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

    $context = new WeeklyTrendTool($user, Carbon::parse('2026-05-01'), app(TrainingLoad::class))->handle([]);

    // CTL: latest 44 minus the one 4 weeks earlier (36) = 8.0.
    expect($context['ctl_delta_4w'])->toBe(8.0)
        ->and($context['volume_recent_4w_km'])->toBe(48.0)  // 12*4
        ->and($context['volume_prev_4w_km'])->toBe(40.0);   // 10*4
});

it('WeeklyTrendTool flags weeks that contain a personal record', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10', 'distance_km' => 20, 'ctl_42d' => 30]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'distance_km' => 25, 'ctl_42d' => 33]);
    // A PR set on Thu 2026-05-14 falls in the week ending Sun 2026-05-17.
    PersonalRecord::factory()->for($user)->create(['set_at' => Carbon::parse('2026-05-14T06:00')]);

    $context = new WeeklyTrendTool($user, Carbon::parse('2026-05-18'), app(TrainingLoad::class))->handle([]);

    $weeks = collect($context['weeks']);
    expect($weeks->firstWhere('ending', '2026-05-17')['pr'])->toBeTrue()
        ->and($weeks->firstWhere('ending', '2026-05-10')['pr'])->toBeFalse();
});

it('WeeklyTrendTool leaves the 4-week deltas null without enough history', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-03', 'distance_km' => 12, 'ctl_42d' => 30,
    ]);

    $context = new WeeklyTrendTool($user, Carbon::parse('2026-05-04'), app(TrainingLoad::class))->handle([]);

    expect($context['ctl_delta_4w'])->toBeNull()
        ->and($context['volume_recent_4w_km'])->toBeNull();
});

it('WeeklyRecapNarrator sends only the continuity line and reads the week', function (): void {
    $user = User::factory()->create();
    $snapshot = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);

    expect(array_keys(new WeeklyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($snapshot)))
        ->toBe(NarratorContinuity::CONTEXT_KEYS);
});

it('MonthlyRecapNarrator sends only the continuity line and reads the month', function (): void {
    $user = User::factory()->create();

    expect(array_keys(new MonthlyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($user, '2026-05')))
        ->toBe(NarratorContinuity::CONTEXT_KEYS);
});

it('TrendCaptionNarrator sends nothing at all, since the caption is entirely a read', function (): void {
    $user = User::factory()->create();

    expect(new TrendCaptionNarrator(fakeCaller('{"caption":"x"}'), app(TrainingLoad::class))
        ->context($user, Carbon::today()))->toBe([]);
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
    $narrator = new CardFlavorNarrator($caller, app(RelativeEffort::class));
    expect($narrator->generate($card))->toBe('Kartu epic!');
});

it('CardFlavorNarrator sends an empty context and lets the model read the card', function (): void {
    $card = cardFixture();

    $names = array_column(
        new CardFlavorNarrator(fakeCaller('{"flavor":"x"}'), app(RelativeEffort::class))
            ->toolbox($card)->definitions(),
        'name',
    );

    expect($names)->toBe([
        'get_card_identity',
        'get_run_summary',
        'get_km_splits',
        'get_weather',
        'get_effort_context',
    ]);
});

it('CardFlavorNarrator drops the run reads when the activity was never detailed', function (): void {
    $card = cardFixture();
    $card->activity->detail->delete();

    $names = array_column(
        new CardFlavorNarrator(fakeCaller('{"flavor":"x"}'), app(RelativeEffort::class))
            ->toolbox($card->fresh())->definitions(),
        'name',
    );

    // Offering tools that can only answer null teaches the model to distrust them.
    expect($names)->toBe(['get_card_identity']);
});

it('CardFlavorNarrator throws on missing flavor key', function (): void {
    $card = cardFixture();
    $caller = fakeCaller(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new CardFlavorNarrator($caller, app(RelativeEffort::class));
    $narrator->generate($card);
})->throws(UnavailableException::class);

it('CardFlavorNarrator throws on non-JSON', function (): void {
    $card = cardFixture();
    $caller = fakeCaller('not json');
    $narrator = new CardFlavorNarrator($caller, app(RelativeEffort::class));
    $narrator->generate($card);
})->throws(UnavailableException::class, 'non-JSON');

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

    $context = new PersonaMixTool($user->fresh(), Carbon::now())->handle([]);

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

    $context = new PersonaMixTool($user->fresh(), Carbon::now())->handle([]);

    expect($context['persona_mix_earlier'][0]['mood'])->toBe('adem')
        ->and($context['persona_mix_recent'][0]['mood'])->toBe('nyala')
        ->and($context['total_runs'])->toBe(3);
});

// ── MonthlyRecapNarrator ──────────────────────────────────────────────

it('MonthTotalsTool reads month totals and the mood mix', function (): void {
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

    $context = new MonthTotalsTool($user, $month)->handle([]);
    expect($context['month'])->toBe('2026-05');
    expect($context['total_runs'])->toBe(1);
    expect($context['total_distance_km'])->toBe(8.0);
    expect($context['longest_run_km'])->toBe(8.0);
    expect($context['mood_mix'][0]['mood'])->toBe('nyala');
    expect($context['pr_count'])->toBe(0);
    expect($context['weekly_distance_km'])->toBeArray();

    expect(new MonthlyRecapNarrator(fakeCaller('{"narrative":"Bulan ini mostly nyala."}'))->generate($user, $month))
        ->toBe('Bulan ini mostly nyala.');
});

it('MonthTotalsTool counts PRs and buckets distance by week within the month', function (): void {
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

    $context = new MonthTotalsTool($user, $month)->handle([]);

    expect($context['pr_count'])->toBe(1)
        ->and($context['weekly_distance_km'][0])->toBe(6.0)
        ->and($context['weekly_distance_km'][2])->toBe(10.0);
});

it('MonthTotalsTool reads the CTL fitness arc from the month snapshots', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-03', 'ctl_42d' => 30.0, 'form_status' => 'optimal',
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-31', 'ctl_42d' => 38.0, 'form_status' => 'fresh',
    ]);

    $context = new MonthTotalsTool($user, '2026-05')->handle([]);

    expect($context['fitness'])->toMatchArray([
        'ctl_start' => 30.0,
        'ctl_end' => 38.0,
        'form_status_end' => 'fresh',
    ]);
});

it('MonthTotalsTool leaves fitness null when the month has no snapshots', function (): void {
    $user = User::factory()->create();

    $context = new MonthTotalsTool($user, '2026-05')->handle([]);

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

    $context = new MonthlyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($user, '2026-05');

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

    $context = new MonthlyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($user, '2026-05');

    expect($context['prev_narrative'])->toBeNull();
});

it('MonthlyRecapNarrator leaves prev_narrative null on the first month', function (): void {
    $user = User::factory()->create();

    $context = new MonthlyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($user, '2026-05');

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

    $context = new LifetimeStatsTool($user->fresh(), Carbon::now(), app(LifetimeStats::class))->handle([]);
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

    $context = new LifetimeStatsTool($user->fresh(), Carbon::now(), app(LifetimeStats::class))->handle([]);

    expect($context['weekly_streak'])->toBe(2)
        ->and($context['favorite_time'])->toBe('malam');
});

it('AkuProfileVoiceNarrator feeds the latest form_status as the consistency spine', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'runs' => 3, 'form_status' => 'overreaching',
    ]);

    $context = new LifetimeStatsTool($user->fresh(), Carbon::now(), app(LifetimeStats::class))->handle([]);

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

    $context = new TrainingPacesTool($user->fresh(), Carbon::now(), app(VdotEstimator::class), app(TrainingPaceCalculator::class))->handle([]);

    expect($context['easy_pace_sec'])->toBeInt()
        ->and($context['marathon_pace_sec'])->toBeInt()
        ->and($context['threshold_pace_sec'])->toBeInt()
        ->and($context['interval_pace_sec'])->toBeInt();
});

it('AkuProfileVoiceNarrator leaves training paces null when the user has no VDOT-eligible PR', function (): void {
    $user = User::factory()->create();

    $context = new TrainingPacesTool($user->fresh(), Carbon::now(), app(VdotEstimator::class), app(TrainingPaceCalculator::class))->handle([]);

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

// ── Prompt wording guards (slice 8 polish) ────────────────────────────

/** Read a narrator's private SYSTEM_PROMPT constant for wording assertions. */
function narratorPrompt(string $class): string
{
    return (string) new ReflectionClass($class)->getConstant('SYSTEM_PROMPT');
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
