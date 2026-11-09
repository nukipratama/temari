<?php

declare(strict_types=1);

use App\Services\Run\Plan\IntentOutcome;
use App\Models\AI\Analysis;
use App\Services\AI\StructuredChatCaller;
use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Actions\Run\Plan\ResolveSeasonAction;
use App\Services\AI\TrendReadFingerprint;
use App\Services\Run\Plan\RacePresenter;
use App\Services\Run\Plan\SupportedTimeHistory;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Models\AI\AnalysisVersion;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\TemariPersona;
use App\Services\AI\Agent\Tools\CardIdentityTool;
use App\Services\AI\Agent\Tools\LifetimeStatsTool;
use App\Services\AI\Agent\Tools\MonthRecordsTool;
use App\Services\AI\Agent\Tools\MonthRunsTool;
use App\Services\AI\Agent\Tools\MonthTotalsTool;
use App\Services\AI\Agent\Tools\TrainingPacesTool;
use App\Services\AI\Agent\Tools\WeekTotalsTool;
use App\Services\AI\Narrators\ProfileVoiceNarrator;
use App\Services\AI\Anchor\CitationValidator;
use App\Services\AI\Anchor\DayAnchorResolver;
use App\Services\AI\Narrators\BriefingMascotVoiceNarrator;
use App\Services\AI\Narrators\CardFlavorNarrator;
use App\Services\AI\Narrators\NarrationContent;
use App\Services\AI\Narrators\NarratorContinuity;
use App\Services\AI\Narrators\PlanVerdictRule;
use App\Enums\IntentVerdict;
use App\Services\AI\Narrators\OutcomeLabels;
use App\Services\AI\Narrators\QuotedFigures;
use App\Services\AI\Narrators\MonthlyRecapNarrator;
use App\Enums\SessionType;
use App\Services\AI\Narrators\PlanClampVoiceNarrator;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\AI\Narrators\PostRunSpeechNarrator;
use App\Services\AI\Anchor\RunAnchorResolver;
use App\Services\AI\Narrators\TrendReadNarrator;
use App\Services\AI\Narrators\WeeklyRecapNarrator;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use App\Services\Run\Plan\TrainingBaseline;
use App\Services\Run\LifetimeStats;
use App\Services\Run\Metrics\DecimalFormatter;
use App\Services\Run\Metrics\DecouplingBands;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\RelativeEffort;
use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\ProgressionSeriesBuilder;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\Vibe;
use App\Support\Numeric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use OpenAI\Resources\Responses;
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

function weeklyRecapNarrator(StructuredChatCaller $caller): WeeklyRecapNarrator
{
    return new WeeklyRecapNarrator($caller, app(TrainingBaseline::class), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(TrainingLoad::class), app(VerdictNarrator::class));
}

function monthlyRecapNarrator(StructuredChatCaller $caller): MonthlyRecapNarrator
{
    return new MonthlyRecapNarrator($caller, app(TrainingBaseline::class), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(TrainingLoad::class), app(VerdictNarrator::class));
}

function postRunNarrator(StructuredChatCaller $caller): PostRunSpeechNarrator
{
    return new PostRunSpeechNarrator($caller, app(TrainingBaseline::class), app(VdotEstimator::class), app(TrainingPaceCalculator::class), new TrainingLoad(), new ResolveRunBaselineAction(), app(RelativeEffort::class), new RunAnchorResolver());
}

/**
 * The merged post-run answer the model returns, reading first.
 *
 * @param  list<array<string, mixed>>  $claims
 */
function speechAnswer(string $title = 'a quiet one', string $body = 'A quiet morning out.', array $claims = [], string $reading = 'conditions'): string
{
    return json_encode(['reading' => $reading, 'title' => $title, 'body' => $body, 'claims' => $claims], JSON_THROW_ON_ERROR);
}

function briefingAnswer(string $title = 'a hook for today', string $body = 'A quiet day.', string $sessionType = 'rest', string $reading = 'plan_reason'): string
{
    return json_encode(['reading' => $reading, 'title' => $title, 'body' => $body, 'session_type' => $sessionType], JSON_THROW_ON_ERROR);
}

/** @return list<array{anchor: string, text: string, value: string|null, delta: string|null}> */
function storedClaims(string $stored): array
{
    return nonNull(nonNull(NarrationContent::fromStored($stored))->claims);
}

function cardFlavorNarrator(StructuredChatCaller $caller): CardFlavorNarrator
{
    return new CardFlavorNarrator($caller, app(RelativeEffort::class), app(TrainingBaseline::class), app(VdotEstimator::class), app(TrainingPaceCalculator::class));
}

function profileVoiceJson(string $paragraph): string
{
    return json_encode([
        'evidence_primary' => '6247.5 km',
        'evidence_secondary' => '',
        'profile_voice' => $paragraph,
    ], JSON_THROW_ON_ERROR);
}

// ── PostRunSpeechNarrator ─────────────────────────────────────────────

/** @return array{activity: Activity, detail: ActivityDetail} */
function postRunFixture(): array
{
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 5000.0,
        'moving_time' => 1500,
        'elapsed_time' => 1500,
    ]);

    return ['activity' => $activity, 'detail' => $detail];
}

it('PostRunSpeechNarrator stores the lowercased title, the body and the claims that resolve', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => [
        'per_km' => [['km' => 1, 'pace' => '6:00'], ['km' => 2, 'pace' => '5:50'], ['km' => 3, 'pace' => '5:45']],
        'drift_metric_version' => 2,
        'steady_effort_decoupling_pct' => 12.0,
    ]]);
    $caller = fakeCaller(speechAnswer('Quicker every km', "First paragraph.\n\nSecond paragraph.", [
        ['anchor' => 'split:2', 'text' => 'Km 2 was the fastest.', 'value' => '5:50/km', 'delta' => null],
        ['anchor' => 'metric:decoupling', 'text' => 'Decoupling ran high.', 'value' => '+12%', 'delta' => null],
        ['anchor' => 'split:99', 'text' => 'A km this run never ran.', 'value' => null, 'delta' => null],
    ], 'pacing'));

    $stored = postRunNarrator($caller)->generate($a, $d->refresh(), 'blazing');

    expect(NarrationContent::fromStored($stored))->toEqual(new NarrationContent('quicker every km', "First paragraph.\n\nSecond paragraph.", [
        ['anchor' => 'split:2', 'text' => 'Km 2 was the fastest.', 'value' => '5:50/km', 'delta' => null],
        ['anchor' => 'metric:decoupling', 'text' => 'Decoupling ran high.', 'value' => '+12%', 'delta' => null],
    ]));
});

it('PostRunSpeechNarrator asks for reading, title, body and claims, the reading picked from its seven angles, with no step override', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    [$caller, $client] = capturingCaller(speechAnswer());

    postRunNarrator($caller)->generate($a, $d, 'easy');

    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $schema = data_get($params, 'text.format.schema');

        return data_get($schema, 'required') === ['reading', 'title', 'body', 'claims']
            && data_get($schema, 'properties.reading.enum') === ['pacing', 'heart_rate', 'effort_vs_usual', 'plan', 'record', 'conditions', 'week_arc']
            && data_get($schema, 'properties.claims.items.required') === ['anchor', 'text', 'value', 'delta']
            && data_get($params, 'max_output_tokens') === 3500;
    });
    expect(declaredMaxSteps(PostRunSpeechNarrator::class))->toBeNull();
});

it('PostRunSpeechNarrator offers exactly the readings its prompt describes and commits to one first', function (): void {
    $schema = new ReflectionClass(PostRunSpeechNarrator::class)->getConstant('PROPERTY_SCHEMA');
    $prompt = narratorPrompt(PostRunSpeechNarrator::class);

    foreach (arrayOf(data_get($schema, 'reading.enum')) as $reading) {
        expect($reading)->toBeString();
        assert(is_string($reading));
        expect($prompt)->toContain("`{$reading}`:");
    }

    expect($prompt)->toContain('COMMIT FIRST')
        ->and($prompt)->toContain('The seven, and `reading` takes exactly one of these values');
});

it('PostRunSpeechNarrator prompt asks for a headline and a 150-220 word story in 2 connected paragraphs, at most 7 numbers outside the claims', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(PostRunSpeechNarrator::class));

    expect($prompt)
        ->toContain('body (2 paragraphs, 150-220 words in total')
        ->toContain('2 paragraphs separated by one blank line')
        ->toContain('at least 3 connected sentences')
        ->toContain('one beat of the same reading')
        ->toContain('never one topic per paragraph like a checklist')
        ->toContain('max 7 numbers across title + body combined')
        ->toContain("The claims carry their own figures in value and delta, and those don't count against it")
        ->toContain('NO PADDING')
        ->and($prompt)->not->toContain('max 75 words')
        ->and($prompt)->not->toContain('200-300')
        ->and($prompt)->not->toContain('2-3 paragraphs')
        ->and($prompt)->not->toContain('2-4 sentences');
});

it('PostRunSpeechNarrator prompt drops the four-blocks lens and the bans it carried', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(PostRunSpeechNarrator::class));

    expect($prompt)->toContain('A claim never repeats a sentence of the body')
        ->and($prompt)->not->toContain('YOUR LENS')
        ->and($prompt)->not->toContain('four blocks')
        ->and($prompt)->not->toContain('Technical translation')
        ->and($prompt)->not->toContain('NEVER dissect pacing');
});

it('PostRunSpeechNarrator ranks the run-scoped reads above the shared week pair', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(PostRunSpeechNarrator::class));

    expect($prompt)
        ->toContain('RUN-SCOPED first')
        ->toContain('get_week_state IS THE LAST RESORT, NEVER THE OPENER')
        ->toContain("the same pair the home page's daily briefing already leads with");
});

it('PostRunSpeechNarrator throws on a missing key', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $caller = fakeCaller(json_encode(['reading' => 'pacing', 'title' => 'x', 'body' => 'y'], JSON_THROW_ON_ERROR));

    postRunNarrator($caller)->generate($a, $d, 'blazing');
})->throws(UnavailableException::class);

it('PostRunSpeechNarrator does not fatal when the stream summary is null, and keeps no claims', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => null]);
    $caller = fakeCaller(speechAnswer(claims: [
        ['anchor' => 'metric:decoupling', 'text' => 'z', 'value' => null, 'delta' => null],
    ]));

    expect(storedClaims(postRunNarrator($caller)->generate($a, $d->refresh(), 'chill')))->toBe([]);
});

it('PostRunSpeechNarrator hands the day\'s judged verdict over up front', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $evidence = ['basis' => 'heart_rate', 'stimulus_family' => 'easy', 'effective_type' => 'easy'];
    PlannedSession::factory()->for($a->user)->create([
        'date' => Carbon::today()->toDateString(),
        'status' => 'done',
        'intent_verdict' => 'hit',
        'intent_evidence' => $evidence,
    ]);

    expect(postRunNarrator(fakeCaller(speechAnswer()))->context($a, $d->refresh(), 'easy')['day_verdict'])
        ->toBe(IntentOutcome::outcome(IntentVerdict::Hit, $evidence));
});

it('PostRunSpeechNarrator hands over no verdict for a day not judged yet', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    PlannedSession::factory()->for($a->user)->create(['date' => Carbon::today()->toDateString(), 'intent_verdict' => null]);

    expect(postRunNarrator(fakeCaller(speechAnswer()))->context($a, $d->refresh(), 'easy'))->not->toHaveKey('day_verdict');
});

it('PostRunSpeechNarrator drops a Z3-or-heavier claim on an easy day judged a hit', function (string $verdict, array $evidence, string $anchor, bool $kept): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => ['time_in_zone_pct' => ['Z2' => 30, 'Z3' => 60, 'Z4' => 8, 'Z5' => 2]]]);
    PlannedSession::factory()->for($a->user)->create([
        'date' => Carbon::today()->toDateString(),
        'status' => 'done',
        'intent_verdict' => $verdict,
        'intent_evidence' => $evidence,
    ]);
    $caller = fakeCaller(speechAnswer(claims: [
        ['anchor' => $anchor, 'text' => 'Most of the run sat in this zone.', 'value' => '60%', 'delta' => null],
    ]));

    expect(storedClaims(postRunNarrator($caller)->generate($a, $d->refresh(), 'easy')))->toHaveCount($kept ? 1 : 0);
})->with([
    'Z3 on an easy hit' => ['hit', ['ceiling_pace_sec' => 443, 'basis' => 'heart_rate'], 'zone:z3', false],
    'Z4 on an easy hit' => ['hit', ['ceiling_pace_sec' => 443, 'basis' => 'heart_rate'], 'zone:z4', false],
    'Z5 on an easy hit' => ['hit', ['ceiling_pace_sec' => 443, 'basis' => 'heart_rate'], 'zone:z5', false],
    'Z2 on an easy hit' => ['hit', ['ceiling_pace_sec' => 443, 'basis' => 'heart_rate'], 'zone:z2', true],
    'Z3 on an easy day run too hard' => ['too_hard', ['ceiling_pace_sec' => 443, 'basis' => 'heart_rate'], 'zone:z3', true],
    'Z3 on a marathon-effort hit' => ['hit', ['ceiling_pace_sec' => 380, 'limit' => 'marathon'], 'zone:z3', true],
]);

it('PostRunSpeechNarrator drops a split claim whose gap to another km is not what the splits say', function (?string $delta, ?string $stored): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => ['per_km' => [
        ['km' => 1, 'pace' => '7:10'], ['km' => 2, 'pace' => '7:05'], ['km' => 3, 'pace' => '7:08'],
        ['km' => 4, 'pace' => '7:01'], ['km' => 5, 'pace' => '7:20'], ['km' => 6, 'pace' => '7:47'],
    ]]]);
    $caller = fakeCaller(speechAnswer(claims: [
        ['anchor' => 'split:6', 'text' => 'km 6 was the slowest.', 'value' => '7:47', 'delta' => $delta],
    ]));

    $claims = storedClaims(postRunNarrator($caller)->generate($a, $d->refresh(), 'wobbly'));

    expect($stored === 'dropped' ? $claims : array_column($claims, 'delta'))->toBe($stored === 'dropped' ? [] : [$stored]);
})->with([
    'the right gap' => ['+0:46 vs km 4', '+0:46 vs km 4'],
    'the right gap with the sign flipped' => ['-0:46 vs km 4', '+0:46 vs km 4'],
    'the right gap, unsigned' => ['0:46 vs km 4', '+0:46 vs km 4'],
    'a wrong gap' => ['-1:00 vs km 4', 'dropped'],
    'a km the run never recorded' => ['-0:30 vs km 9', 'dropped'],
    'no comparison' => [null, null],
    'a comparison to something other than a km' => ['+0:12 vs 28d avg', '+0:12 vs 28d avg'],
]);

it('PostRunSpeechNarrator keeps a claim anchored to a reading the run has', function (array $summary, string $anchor): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => $summary]);
    $caller = fakeCaller(speechAnswer(claims: [
        ['anchor' => $anchor, 'text' => 'A real reading.', 'value' => null, 'delta' => null],
    ]));

    expect(storedClaims(postRunNarrator($caller)->generate($a, $d->refresh(), 'easy')))->toHaveCount(1);
})->with([
    'a split the run has' => [['per_km' => [['km' => 1, 'pace' => '6:00'], ['km' => 2, 'pace' => '5:50']]], 'split:2'],
    'a zone with a real HR breakdown' => [['time_in_zone_pct' => ['Z2' => 70, 'Z3' => 30]], 'zone:z2'],
]);

it('PostRunSpeechNarrator drops a claim whose anchor the run has no reading for', function (string $anchor, string $text): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => ['per_km' => [['km' => 1, 'pace' => '6:00']], 'decoupling_pct' => 3.0]]);
    $caller = fakeCaller(speechAnswer(claims: [
        ['anchor' => $anchor, 'text' => $text, 'value' => null, 'delta' => null],
    ]));

    expect(storedClaims(postRunNarrator($caller)->generate($a, $d->refresh(), 'easy')))->toBe([]);
})->with([
    'a split the run never recorded' => ['split:5', 'A km that never happened.'],
    'a zone with no HR breakdown' => ['zone:z4', 'Mostly Z4.'],
    'a metric never computed for this run' => ['metric:hr_drift', 'HR crept up.'],
    'an anchor outside the closed namespace' => ['weather:hot', 'It was hot.'],
    'empty text' => ['split:1', ''],
]);

it('PostRunSpeechNarrator caps claims at 3 even when the model returns more valid ones', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => [
        'drift_metric_version' => 2,
        'steady_effort_decoupling_pct' => 6.0,
        'steady_effort_hr_drift_bpm' => 5.0,
        'cadence_drop_spm' => 3.0,
        'pace_variability_sec' => 20.0,
    ]]);
    $caller = fakeCaller(speechAnswer(claims: [
        ['anchor' => 'metric:decoupling', 'text' => 'a', 'value' => null, 'delta' => null],
        ['anchor' => 'metric:hr_drift', 'text' => 'b', 'value' => null, 'delta' => null],
        ['anchor' => 'metric:cadence_drop', 'text' => 'c', 'value' => null, 'delta' => null],
        ['anchor' => 'metric:pace_variability', 'text' => 'd', 'value' => null, 'delta' => null],
    ]));

    expect(storedClaims(postRunNarrator($caller)->generate($a, $d->refresh(), 'easy')))->toHaveCount(3);
});

it('PostRunSpeechNarrator keeps only the first claim on each anchor', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => [
        'drift_metric_version' => 2,
        'steady_effort_decoupling_pct' => 6.0,
        'pace_variability_sec' => 20.0,
    ]]);
    $caller = fakeCaller(speechAnswer(claims: [
        ['anchor' => 'metric:decoupling', 'text' => 'a', 'value' => null, 'delta' => null],
        ['anchor' => 'metric:decoupling', 'text' => 'b', 'value' => null, 'delta' => null],
        ['anchor' => 'metric:pace_variability', 'text' => 'c', 'value' => null, 'delta' => null],
        ['anchor' => 'metric:decoupling', 'text' => 'd', 'value' => null, 'delta' => null],
    ]));

    expect(array_column(storedClaims(postRunNarrator($caller)->generate($a, $d->refresh(), 'easy')), 'text'))->toBe(['a', 'c']);
});

it('PostRunSpeechNarrator prompt carries the readings it took over from the run insight', function (string $first, string $second): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(PostRunSpeechNarrator::class));

    expect($prompt)->toContain($first)
        ->and($prompt)->toContain($second);
})->with([
    'quality-session framing so it stops assuming easy' => ['session_intent', 'QUALITY SESSIONS'],
    'fetches its own numbers and does not invent the rest' => ['Fetch them yourself', 'NEVER make up'],
    'a lap-structured session is the shape, not messy pacing' => ['STRUCTURED SESSION', 'recovery_sec'],
    'a missing reading is not material' => ['it is simply not material for this run', "Don't mention that something is missing"],
    'claims stay short' => ['text (REQUIRED): the observation, 1-2 sentences', 'NEVER pad'],
    'heat explains decoupling' => ['WEATHER & DECOUPLING', 'not a sign of lost fitness'],
    'rain is hedged when forecast' => ['weather_rain_source', 'hedge ("forecast called for light rain")'],
    'grey zone skips a day judged easy' => ['GREY ZONE', 'Skip it too if day_verdict in your context says the day stayed at the easy effort it asked for'],
    'the effort label never judges the ask' => ["belongs to the day's verdict from get_planned_sessions", 'intensity_label'],
    'the effort score disagreeing with the verdict says both' => ['HOW IT FELT', 'say both: the verdict reads the watch, the score is how it felt'],
    'the PR gate' => ['NEVER mention "PR" or "personal record" unless', 'get_personal_records AND the list actually has something in it'],
    'the coast is named once and only when unexplained' => ['COASTING', 'And never twice.'],
    'the baseline is not a past run' => ['PAST YOU', 'The 28-day average from get_recent_baseline is not a past run'],
    'no grades and no next session' => ['never as a grade or a score out of anything', 'NEVER prescribe the next session'],
    'plain, natural phrasing with run terms kept' => ['LANGUAGE:', 'plain and conversational, not clinical'],
]);

it('PostRunSpeechNarrator sends a prompt with no em-dash and the decoupling band it reads filled in', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    [$caller, $client] = capturingCaller(speechAnswer());

    postRunNarrator($caller)->generate($a, $d, 'easy');

    expect(narratorPrompt(PostRunSpeechNarrator::class))->not->toContain('—');
    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $system = (string) $params['input'][0]['content'];

        return ! str_contains($system, '{decoupling_high}')
            && str_contains(preg_replace('/\s+/', ' ', $system) ?? '', 'decoupling.pct is high (>'.DecimalFormatter::trimmed(DecouplingBands::HIGH).'%)');
    });
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
        'elapsed_time' => 1200,
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
    priorActivityWithDoneAnalysis($a->user, AnalysisType::PostRunSpeech, 'The run yesterday was very easy.');

    $context = postRunNarrator(fakeCaller(speechAnswer()))->context($a, $d->refresh(), 'blazing');

    expect($context['prev_narrative'])->toBe('The run yesterday was very easy.')
        ->and($context['prev_opener'])->toBe('The run yesterday was very easy.');
});

it('PostRunSpeechNarrator feeds a merged predecessor as its title and body, never its claims', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    priorActivityWithDoneAnalysis($a->user, AnalysisType::PostRunSpeech, new NarrationContent('the hills won', 'Body of it.', [
        ['anchor' => 'metric:grade', 'text' => 'Steepest stretch.', 'value' => '8%', 'delta' => null],
    ])->toStored());

    $context = postRunNarrator(fakeCaller(speechAnswer()))->context($a, $d->refresh(), 'blazing');

    expect($context['prev_narrative'])->toBe("the hills won\n\nBody of it.")
        ->and($context['prev_opener'])->toBe('the hills won');
});

it('PostRunSpeechNarrator leaves prev_narrative null when there is no prior Done post-run', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $prior = Activity::factory()->for($a->user)->analyzed()->create();
    ActivityDetail::factory()->for($prior)->create(['start_date_local' => Carbon::parse('2026-05-09')]);
    Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $prior->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    $context = postRunNarrator(fakeCaller(speechAnswer()))->context($a, $d->refresh(), 'blazing');

    expect($context['prev_narrative'])->toBeNull()
        ->and($context['prev_opener'])->toBeNull();
});

it('PostRunSpeechNarrator truncates prev_opener to the first few words of a long prior narrative', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    priorActivityWithDoneAnalysis(
        $a->user,
        AnalysisType::PostRunSpeech,
        'Still carrying yesterday, and this time your close was livelier with a tidier finishing pace.',
    );

    $context = postRunNarrator(fakeCaller(speechAnswer()))->context($a, $d->refresh(), 'blazing');

    expect($context['prev_opener'])->toBe('Still carrying yesterday, and this time your close was livelier')
        ->and(str_word_count(Arr::string($context, 'prev_opener')))->toBeLessThanOrEqual(10);
});

it('PostRunSpeechNarrator keeps only what no tool can serve in the context', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $context = postRunNarrator(fakeCaller(speechAnswer()))
        ->context($a, $d->refresh(), 'blazing');

    expect(array_keys($context))
        ->toBe(['mood', ...NarratorContinuity::CONTEXT_KEYS]);
});

it('PostRunSpeechNarrator offers every run reading as a tool bound to this activity', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $names = array_column(
        postRunNarrator(fakeCaller(speechAnswer()))
            ->toolbox($a, $d)->definitions(),
        'name',
    );

    expect($names)->toBe([
        'get_run_summary',
        'get_km_splits',
        'get_laps',
        'get_hr_zones',
        'get_terrain',
        'get_weather',
        'get_effort_context',
        'get_training_load',
        'get_recent_baseline',
        'get_training_paces',
        'get_planned_sessions',
        'get_perceived_effort',
        'get_personal_records',
        'get_week_state',
    ]);
});

it('BriefingMascotVoiceNarrator reads the day, the 28d baseline and what was prescribed', function (): void {
    $user = User::factory()->create();
    $narrator = app(BriefingMascotVoiceNarrator::class);

    expect(array_column($narrator->toolbox($user, Carbon::today())->definitions(), 'name'))
        ->toBe(['get_week_state', 'get_recent_runs', 'get_training_load', 'get_recent_baseline', 'get_planned_sessions']);
});

// The prompt no longer invites stating a past-run comparison; see the
// structural guard below.
it('BriefingMascotVoiceNarrator prompt forbids comparing today or the last run to a specific past run', function (): void {
    $prompt = narratorPrompt(BriefingMascotVoiceNarrator::class);

    expect($prompt)->toContain('NEVER compare')
        ->and($prompt)->not->toContain('get_latest_past_you');
});

it('BriefingMascotVoiceNarrator prompt tells the model to fetch its own numbers and not invent the rest', function (): void {
    $prompt = narratorPrompt(BriefingMascotVoiceNarrator::class);

    expect($prompt)->toContain('Fetch them yourself')
        ->and($prompt)->toContain('NEVER make up');
});

it('BriefingMascotVoiceNarrator offers exactly the readings its prompts describe and commits to one first', function (string $constant, string $schemaConstant, string $count): void {
    $schema = new ReflectionClass(BriefingMascotVoiceNarrator::class)->getConstant($schemaConstant);
    $prompt = narratorConstant(BriefingMascotVoiceNarrator::class, $constant);

    foreach (arrayOf(data_get($schema, 'reading.enum')) as $reading) {
        expect($reading)->toBeString();
        assert(is_string($reading));
        expect($prompt)->toContain("`{$reading}`:");
    }

    expect($prompt)->toContain('COMMIT FIRST')
        ->and($prompt)->toContain("The {$count}, and `reading` takes exactly one of these values");
})->with([
    'pre-run' => ['SYSTEM_PROMPT', 'READING_PROPERTY_SCHEMA', 'seven'],
    'post-run' => ['POST_RUN_SYSTEM_PROMPT', 'POST_RUN_READING_PROPERTY_SCHEMA', 'six'],
]);

it('BriefingMascotVoiceNarrator prompts ask for a headline and a 120-180 word story in 1-2 connected paragraphs, at most 5 numbers', function (string $constant): void {
    $prompt = preg_replace('/\s+/', ' ', narratorConstant(BriefingMascotVoiceNarrator::class, $constant));

    expect($prompt)
        ->toContain('body (1-2 paragraphs, 120-180 words in total')
        ->toContain('1-2 paragraphs separated by one blank line')
        ->toContain('at least 3 connected sentences')
        ->toContain('one beat of the same reading')
        ->toContain('never one topic per paragraph like a checklist')
        ->toContain('max 5 numbers across title + body combined')
        ->toContain('WHERE THE LENGTH COMES FROM')
        ->toContain('NO PADDING')
        ->toContain('never to filler')
        ->and($prompt)->not->toContain('REQUIRED STRUCTURE')
        ->and($prompt)->not->toContain('PARAGRAPH 3')
        ->and($prompt)->not->toContain('WHAT TO WATCH:')
        ->and($prompt)->not->toContain('Max 150 words')
        ->and($prompt)->not->toContain('mascot_voice');
})->with(['pre-run' => ['SYSTEM_PROMPT'], 'post-run' => ['POST_RUN_SYSTEM_PROMPT']]);

it('BriefingMascotVoiceNarrator prompt asks for a title with a hook for today, never the bare session label', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(BriefingMascotVoiceNarrator::class));

    expect($prompt)
        ->toContain('names today\'s session AND the hook that is specific to today')
        ->toContain('Never the bare session label')
        ->toContain('The title never carries the [words](session:today) link')
        ->and($prompt)->not->toContain('This is the only part allowed to read dry');
});

it('BriefingMascotVoiceNarrator prompt keeps the single session link, the time-word ban and the intensity ceiling', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(BriefingMascotVoiceNarrator::class));

    expect($prompt)
        ->toContain('At most ONE of these in the whole block')
        ->toContain('This is the only link form allowed')
        ->toContain('THE BAN IS ON THE WORDS, IN ANY POSITION')
        ->toContain('INTENSITY CEILING (REQUIRED, NEVER BREAK)')
        ->toContain('Start from what was prescribed')
        ->toContain('NEVER compare');
});

it('BriefingMascotVoiceNarrator post-run prompt asks for a title about the run and the rest of the day, with no second run', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorConstant(BriefingMascotVoiceNarrator::class, 'POST_RUN_SYSTEM_PROMPT'));

    expect($prompt)
        ->toContain('the headline about the run that just landed and what it means for the rest of today and the week')
        ->toContain('Do not offer another run today')
        ->toContain('The `session_type` output field MUST be `rest`')
        ->toContain('- "10 km done against 6.2 km planned, harder than the easy day asked for."');
});

// ── WeeklyRecapNarrator ───────────────────────────────────────────────

it('WeeklyRecapNarrator stores its headline and story as JSON content, not the reading', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek()->toDateString(),
        'distance_km' => 30.0,
        'runs' => 4,
    ]);
    $caller = fakeCaller(json_encode(['reading' => 'standout_run', 'title' => 'the long run carried it.', 'body' => 'Solid week.'], JSON_THROW_ON_ERROR));

    $stored = weeklyRecapNarrator($caller)->generate($snap);

    expect($stored)->toBe(json_encode(['title' => 'the long run carried it.', 'body' => 'Solid week.'], JSON_THROW_ON_ERROR))
        ->and(NarrationContent::fromStored($stored))->toEqual(new NarrationContent('the long run carried it.', 'Solid week.'));
});

it('WeeklyRecapNarrator stores a capitalised headline in lowercase and leaves the body alone', function (): void {
    $snap = WeeklySnapshot::factory()->for(User::factory()->create())->create([
        'week_ending' => Carbon::today()->endOfWeek()->toDateString(),
        'distance_km' => 30.0,
        'runs' => 4,
    ]);
    $caller = fakeCaller(json_encode(['reading' => 'standout_run', 'title' => 'The long run carried it.', 'body' => 'Solid week.'], JSON_THROW_ON_ERROR));

    $stored = weeklyRecapNarrator($caller)->generate($snap);

    expect(NarrationContent::fromStored($stored))->toEqual(new NarrationContent('the long run carried it.', 'Solid week.'));
});

it('WeeklyRecapNarrator asks for reading, title and body, the reading picked from its six angles', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);
    [$caller, $client] = capturingCaller(json_encode(['reading' => 'plan', 'title' => 't', 'body' => 'b'], JSON_THROW_ON_ERROR));

    weeklyRecapNarrator($caller)->generate($snap);

    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $schema = data_get($params, 'text.format.schema');

        return data_get($schema, 'required') === ['reading', 'title', 'body']
            && data_get($schema, 'properties.reading.enum') === ['vs_last_week', 'plan', 'standout_run', 'records', 'long_arc', 'load']
            && data_get($params, 'max_output_tokens') === 2500;
    });
});

it('WeeklyRecapNarrator throws on a missing body key', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek()->toDateString(),
    ]);
    $caller = fakeCaller(json_encode(['reading' => 'load', 'title' => 'x'], JSON_THROW_ON_ERROR));
    weeklyRecapNarrator($caller)->generate($snap);
})->throws(UnavailableException::class);

it('WeeklyRecapNarrator hands the model the week, its runs, its plan, its records and the six-week arc', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);

    expect(array_column(weeklyRecapNarrator(fakeCaller('{}'))->toolbox($snap)->definitions(), 'name'))
        ->toBe(['get_week_totals', 'get_week_runs', 'get_planned_sessions', 'get_week_records', 'get_trend_range_totals']);
});

it('WeeklyRecapNarrator reads the six-week arc as it stood on the week Sunday, not today', function (): void {
    Carbon::setTestNow('2026-06-20 12:00:00');
    $user = User::factory()->create();
    $inArc = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($inArc)->create(['start_date_local' => '2026-05-17 07:00:00', 'distance' => 5000.0]);
    $afterWeek = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($afterWeek)->create(['start_date_local' => '2026-05-18 07:00:00', 'distance' => 9000.0]);
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);

    $arc = json_decode(weeklyRecapNarrator(fakeCaller('{}'))->toolbox($snap)->invoke('get_trend_range_totals', '{}'), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($arc, 'range'))->toBe('6w')
        ->and(data_get($arc, 'current.runs'))->toBe(1)
        ->and(data_get($arc, 'current.distance_km'))->toEqual(5.0);
    Carbon::setTestNow();
});

it('WeekTotalsTool reads the previous week deltas when a prior snapshot exists', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-10', 'distance_km' => 20.0, 'runs' => 3, 'elapsed_time_sec' => 7200,
    ]);
    $current = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17', 'distance_km' => 28.0, 'runs' => 4, 'elapsed_time_sec' => 9600,
    ]);

    $context = new WeekTotalsTool($current)->handle([]);

    expect($context['prev_distance_km'])->toBe(20.0)
        ->and($context['prev_runs'])->toBe(3)
        ->and($context['prev_pace_sec_per_km'])->not->toBeNull()
        ->and($context['pace_formatted'])->toBe(PaceFormatter::format(Numeric::float($context['pace_sec_per_km'])))
        ->and($context['prev_pace_formatted'])->toBe(PaceFormatter::format(Numeric::float($context['prev_pace_sec_per_km'])));
});

it('WeeklyRecapNarrator leaves previous-week deltas null on the first week', function (): void {
    $user = User::factory()->create();
    $current = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);

    $context = new WeekTotalsTool($current)->handle([]);

    expect($context['prev_distance_km'])->toBeNull()
        ->and($context['prev_runs'])->toBeNull()
        ->and($context['prev_pace_sec_per_km'])->toBeNull()
        ->and($context['prev_pace_formatted'])->toBeNull();
});

it('WeekTotalsTool leaves previous-week fields null when the previous week had no runs', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-10', 'distance_km' => 0.0, 'runs' => 0, 'elapsed_time_sec' => 0,
    ]);
    $current = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);

    $context = new WeekTotalsTool($current)->handle([]);

    expect($context['prev_runs'])->toBeNull()
        ->and($context['prev_distance_km'])->toBeNull()
        ->and($context['prev_pace_sec_per_km'])->toBeNull()
        ->and($context['prev_pace_formatted'])->toBeNull();
});

it('PostRunSpeechNarrator gives the effort score to the effort_vs_usual reading and quotes the formatted easy pace', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(PostRunSpeechNarrator::class));

    expect($prompt)
        ->toContain('The effort score the athlete gave, from get_perceived_effort, against the range that session usually feels belongs here too')
        ->toContain('the effort score is a number of the `effort_vs_usual` reading, so quote it only when that is your reading')
        ->toContain('quote easy_pace_formatted from get_training_paces')
        ->and($prompt)->not->toContain('convert easy_pace_sec');
});

it('the weekly and monthly recap prompts point at get_planned_sessions for ran_anyway and overridden, and ban listing days and a compliance score', function (string $class, string $runsTool): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt($class));

    expect($prompt)
        ->toContain("The planned field on each run from {$runsTool} only carries the session type and its grade, never those two flags")
        ->toContain('Never list days, and never quote a compliance score')
        ->and($prompt)->not->toContain('get_planned_sessions and the planned field');
})->with([
    'weekly' => [WeeklyRecapNarrator::class, 'get_week_runs'],
    'monthly' => [MonthlyRecapNarrator::class, 'get_month_runs'],
]);

it('MonthlyRecapNarrator takes the long_arc numbers from get_trend_range_totals alone and leaves the thread to the continuity rule', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(MonthlyRecapNarrator::class));

    expect($prompt)
        ->toContain('or ctl_start against ctl_end (long-term load). A null field is no reading, not zero, so skip it.')
        ->toContain('prev_narrative is never a source of figures')
        ->and($prompt)->not->toContain('from there or from')
        ->and($prompt)->not->toContain('a thread to pick up');
});

it('BriefingMascotVoiceNarrator says its week-state read-only fields have their own tools for the last two', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(BriefingMascotVoiceNarrator::class));

    expect($prompt)->toContain('get_week_state carries every field below except the last two, which have their own tools');
});

it('WeekTotalsTool formats a sub-4:00 average pace the same way the app shows it', function (): void {
    $user = User::factory()->create();
    $current = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17', 'distance_km' => 10.0, 'elapsed_time_sec' => 2320, // 3:52/km
    ]);

    expect(new WeekTotalsTool($current)->handle([])['pace_formatted'])->toBe('3:52');
});

it('WeeklyRecapNarrator feeds prev_narrative when the prior week recap is Done', function (): void {
    $user = User::factory()->create();
    $prior = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10']);
    Analysis::factory()->done('You were solid last week.')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $prior->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);
    $current = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);

    $context = weeklyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($current);

    expect($context['prev_narrative'])->toBe('You were solid last week.');
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

    $context = weeklyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($current);

    expect($context['prev_narrative'])->toBeNull();
});

// avg_decoupling carries its own relation, resolved from the sign alone via
// the DecouplingBands::TIGHT flat band.
it('WeekTotalsTool reads avg_decoupling with no signed field for the week', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17', 'avg_decoupling' => 12.0, 'avg_decoupling_v2' => 6.4,
    ]);

    $context = new WeekTotalsTool($snap)->handle([]);

    expect($context['avg_decoupling'])->toBe(['pct' => 6.4, 'relation' => 'up']);
});

it('does not fall back to the historical weekly average when v2 is unavailable', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17', 'avg_decoupling' => 12.0,
    ]);

    expect(new WeekTotalsTool($snap)->handle([])['avg_decoupling'])->toBeNull();
});

it('WeekTotalsTool reads the load balance as a state with no signed field', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17', 'form' => -8.0, 'form_status' => 'fatigued',
    ]);

    $context = new WeekTotalsTool($snap)->handle([]);

    expect($context['load_balance'])->toBe('heavy')
        ->and($context)->not->toHaveKeys(['form', 'form_status']);
});

// ── TrendReadNarrator ─────────────────────────────────────────────────

function trendReadNarrator(StructuredChatCaller $caller): TrendReadNarrator
{
    return new TrendReadNarrator($caller, app(TrainingLoad::class), app(ResolveActiveRaceAction::class), app(ResolveSeasonAction::class), app(RacePresenter::class), app(SupportedTimeHistory::class));
}

function trendReadAnswer(string $reading = 'volume_arc', string $title = 'six weeks bigger than the six before.', string $body = 'The volume climbed.'): string
{
    return json_encode(['reading' => $reading, 'title' => $title, 'body' => $body], JSON_THROW_ON_ERROR);
}

it('TrendReadNarrator stores its headline in lowercase and its story as JSON content, not the reading', function (): void {
    $user = User::factory()->create();

    $stored = trendReadNarrator(fakeCaller(trendReadAnswer(title: 'Six weeks bigger than the six before.', body: "The volume climbed.\n\nThe plan held.")))->generate($user);

    expect($stored)->toBe(json_encode(['title' => 'six weeks bigger than the six before.', 'body' => "The volume climbed.\n\nThe plan held."], JSON_THROW_ON_ERROR));
});

it('TrendReadNarrator throws on a missing body key', function (): void {
    trendReadNarrator(fakeCaller(json_encode(['reading' => 'volume_arc', 'title' => 'x'], JSON_THROW_ON_ERROR)))->generate(User::factory()->create());
})->throws(UnavailableException::class);

it('TrendReadNarrator picks the race read from the data: an active race gets the race prompt, readings and tool', function (bool $withRace, string $promptConstant, array $readings, array $tools): void {
    /** @var list<string> $readings */
    /** @var list<string> $tools */
    Carbon::setTestNow('2026-08-19 12:00:00');
    $user = User::factory()->create();
    if ($withRace) {
        RaceGoal::factory()->for($user)->create();
    }
    [$caller, $client] = capturingCaller(trendReadAnswer($readings[0]));

    trendReadNarrator($caller)->generate($user);

    $client->assertSent(Responses::class, function (string $method, array $params) use ($promptConstant, $readings, $tools): bool {
        $schema = data_get($params, 'text.format.schema');
        $system = (string) $params['input'][0]['content'];

        return data_get($schema, 'required') === ['reading', 'title', 'body']
            && data_get($schema, 'properties.reading.enum') === $readings
            && array_column(arrayOf(data_get($params, 'tools')), 'name') === $tools
            && str_contains($system, trim(narratorConstant(TrendReadNarrator::class, $promptConstant)))
            && data_get($params, 'max_output_tokens') === 2500
            && str_contains($system, NarratorContinuity::RULE)
            && $params['input'][1]['content'] === json_encode(['closed_weeks_through' => '2026-08-16', 'prev_narrative' => null, 'prev_opener' => null, 'recent_openers' => []], JSON_THROW_ON_ERROR);
    });
    Carbon::setTestNow();
})->with([
    'no race' => [false, 'SYSTEM_PROMPT', ['volume_arc', 'load_direction', 'adherence'], ['get_trend_range_totals', 'get_plan_adherence']],
    'race' => [true, 'RACE_SYSTEM_PROMPT', ['on_track', 'gap_to_target', 'support_trend', 'adherence', 'load_direction'], ['get_race_outlook', 'get_trend_range_totals', 'get_plan_adherence']],
]);

it('TrendReadNarrator reads the six closed weeks ending last Sunday, never the week still open', function (): void {
    Carbon::setTestNow('2026-08-19 12:00:00');
    $user = User::factory()->create();
    $closed = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($closed)->create(['start_date_local' => '2026-08-16 07:00:00', 'distance' => 5000.0]);
    $open = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($open)->create(['start_date_local' => '2026-08-18 07:00:00', 'distance' => 9000.0]);
    PlannedSession::factory()->for($user)->create(['date' => '2026-07-06']);
    PlannedSession::factory()->for($user)->create(['date' => '2026-07-05']);
    PlannedSession::factory()->for($user)->create(['date' => '2026-08-18']);

    $toolbox = trendReadNarrator(fakeCaller('{}'))->toolbox($user, null, TrendReadFingerprint::closedThrough());
    $arc = json_decode($toolbox->invoke('get_trend_range_totals', '{}'), true, flags: JSON_THROW_ON_ERROR);
    $adherence = json_decode($toolbox->invoke('get_plan_adherence', '{}'), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($arc, 'range'))->toBe('6w')
        ->and(data_get($arc, 'current.runs'))->toBe(1)
        ->and(data_get($arc, 'current.distance_km'))->toEqual(5.0)
        ->and(data_get($adherence, 'from'))->toBe('2026-07-06')
        ->and(data_get($adherence, 'through'))->toBe('2026-08-16')
        ->and(data_get($adherence, 'prescribed'))->toBe(1);
    Carbon::setTestNow();
});

it('TrendReadNarrator reads race adherence across the build its season opened', function (): void {
    Carbon::setTestNow('2026-08-19 12:00:00');
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create();
    Season::factory()->for($user)->create(['race_goal_id' => $race->id, 'starts_at' => '2026-05-04']);
    PlannedSession::factory()->for($user)->create(['date' => '2026-05-04']);
    PlannedSession::factory()->for($user)->create(['date' => '2026-05-03']);

    $toolbox = trendReadNarrator(fakeCaller('{}'))->toolbox($user, $race, TrendReadFingerprint::closedThrough());
    $adherence = json_decode($toolbox->invoke('get_plan_adherence', '{}'), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($adherence, 'from'))->toBe('2026-05-04')
        ->and(data_get($adherence, 'prescribed'))->toBe(1);
    Carbon::setTestNow();
});

function trendReadRow(User $user, ?string $content, string $discriminator = 'arc'): Analysis
{
    return Analysis::factory()->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => $discriminator,
        'status' => AnalysisStatus::Processing,
        'content' => $content,
    ]);
}

/** @param list<string> $openers */
function trendReadVersions(Analysis $row, array $openers): void
{
    foreach ($openers as $opener) {
        AnalysisVersion::query()->create(['analysis_id' => $row->id, 'content' => jsonNarration($opener, 'An older read.')]);
    }
}

it('TrendReadNarrator rotates against its own row: the read it still holds, then its versions newest first', function (?string $current, array $versions, array $openers, ?string $prevNarrative): void {
    /** @var list<string> $versions */
    $user = User::factory()->create();
    if ($current !== null || $versions !== []) {
        trendReadVersions(trendReadRow($user, $current), $versions);
    }
    trendReadVersions(trendReadRow(User::factory()->create(), jsonNarration('another athlete', 'Body.')), ['another athlete older']);
    [$caller, $client] = capturingCaller(trendReadAnswer());

    trendReadNarrator($caller)->generate($user);

    $client->assertSent(Responses::class, function (string $method, array $params) use ($openers, $prevNarrative): bool {
        $context = json_decode(Arr::string($params['input'][1], 'content'), true);

        return is_array($context)
            && $context['recent_openers'] === $openers
            && $context['prev_opener'] === ($openers[0] ?? null)
            && $context['prev_narrative'] === $prevNarrative;
    });
})->with([
    'no history' => [null, [], [], null],
    'the current read only' => [jsonNarration('the read it holds', 'Body.'), [], ['the read it holds'], "the read it holds\n\nBody."],
    'a plain pre-redesign read' => ['the six weeks held.', [], ['the six weeks held.'], 'the six weeks held.'],
    'the current read, then versions newest first' => [jsonNarration('the read it holds', 'Body.'), ['oldest', 'middle', 'newest'], ['the read it holds', 'newest', 'middle', 'oldest'], "the read it holds\n\nBody."],
    'versions only, before a first read landed' => [null, ['oldest', 'newest'], ['newest', 'oldest'], "newest\n\nAn older read."],
    'capped at the window' => [jsonNarration('the read it holds', 'Body.'), ['v1', 'v2', 'v3', 'v4', 'v5', 'v6'], ['the read it holds', 'v6', 'v5', 'v4', 'v3'], "the read it holds\n\nBody."],
]);

it('TrendReadNarrator ignores a retired 7d trend read and reads its versions in one bounded query', function (): void {
    $user = User::factory()->create();
    trendReadVersions(trendReadRow($user, jsonNarration('a 7d read', 'Body.'), '7d'), ['a 7d version']);
    trendReadVersions(trendReadRow($user, jsonNarration('the arc read', 'Body.')), ['v1', 'v2', 'v3', 'v4', 'v5', 'v6', 'v7']);
    [$caller, $client] = capturingCaller(trendReadAnswer());

    DB::enableQueryLog();
    trendReadNarrator($caller)->generate($user);
    $versionQueries = array_values(array_filter(
        array_column(DB::getQueryLog(), 'query'),
        fn (string $query): bool => str_contains($query, 'analysis_versions'),
    ));

    expect($versionQueries)->toHaveCount(1)
        ->and($versionQueries[0])->toContain('limit 5');
    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $context = json_decode(Arr::string($params['input'][1], 'content'), true);

        return is_array($context) && $context['recent_openers'] === ['the arc read', 'v7', 'v6', 'v5', 'v4'];
    });
});

/**
 * @param  array<string, mixed>  $context
 */
function assertOneStepWithContext(ClientFake $client, array $context): void
{
    $client->assertSent(Responses::class, 1);
    $client->assertSent(Responses::class, fn (string $method, array $params): bool => $method === 'create'
        && $params['input'][1]['content'] === json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        && ! array_key_exists('tools', $params));
}

it('PlanClampVoiceNarrator hands the eased session and its reasons in the user message and answers in one step with no tools', function (): void {
    [$caller, $client] = capturingCaller(json_encode(['voice' => 'you ran long yesterday, so today is an easy day.'], JSON_THROW_ON_ERROR));

    $voice = new PlanClampVoiceNarrator($caller)->generate([
        'ceiling' => ReadinessCeiling::ModerateOk,
        'original' => SessionType::Interval,
        'clamped_to' => SessionType::Easy,
        'has_run_today' => false,
        'readiness_reasons' => ['demanding_session_within_24h'],
        'readiness_inputs' => [],
    ], 7, Carbon::parse('2026-11-09'));

    expect($voice)->toBe('you ran long yesterday, so today is an easy day.');
    assertOneStepWithContext($client, [
        'planned' => 'interval',
        'stepped_down_to' => 'easy',
        'readiness_reasons' => ['demanding_session_within_24h'],
        ...NarratorContinuity::fields([]),
    ]);
});

it('PlanClampVoiceNarrator lowercases an ordinary first word and keeps an acronym', function (string $voice, string $expected): void {
    $caller = fakeCaller(json_encode(['voice' => $voice], JSON_THROW_ON_ERROR));
    $context = [
        'ceiling' => ReadinessCeiling::ModerateOk,
        'original' => SessionType::Interval,
        'clamped_to' => SessionType::Easy,
        'has_run_today' => false,
        'readiness_reasons' => ['demanding_session_within_24h'],
        'readiness_inputs' => [],
    ];

    expect(new PlanClampVoiceNarrator($caller)->generate($context, 7, Carbon::parse('2026-11-09')))->toBe($expected);
})->with([
    'an ordinary word' => ['You ran long yesterday, so today is an easy day.', 'you ran long yesterday, so today is an easy day.'],
    'an acronym' => ['HR was high yesterday, so today is an easy day.', 'HR was high yesterday, so today is an easy day.'],
    'two sentences' => ['Load has been stacking up. Today gives it somewhere to go.', 'Load has been stacking up. Today gives it somewhere to go.'],
]);

it('PlanClampVoiceNarrator throws on missing voice key', function (): void {
    $caller = fakeCaller(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));

    new PlanClampVoiceNarrator($caller)->generate([
        'ceiling' => ReadinessCeiling::ModerateOk,
        'original' => SessionType::Interval,
        'clamped_to' => SessionType::Easy,
        'has_run_today' => false,
        'readiness_reasons' => [],
        'readiness_inputs' => [],
    ], 7, Carbon::parse('2026-11-09'));
})->throws(UnavailableException::class);

it('PlanClampVoiceNarrator names the eased session as the one being run today', function (string $narrator): void {
    $prompt = narratorPrompt($narrator);

    expect($prompt)->toContain('stepped_down_to is the session the athlete is running today')
        ->and($prompt)->not->toContain('SAY WHY, NOT WHAT');
})->with([PlanClampVoiceNarrator::class => [PlanClampVoiceNarrator::class]]);

it('OutcomeLabels catches a verdict label, and passes a described outcome', function (string $text, bool $label, string $labels): void {
    assert(is_a($labels, OutcomeLabels::class, true));

    expect($labels::labelIn($text))->toBe($label)
        ->and($labels::complaint($text, 'voice') === null)->toBe(! $label);
})->with([
    'the inverted prod read' => ['the pace sat at 7:29/km, so it missed the hit mark.', true],
    'a bold enum' => ['easy day, **too_hard** at 6:20/km.', true],
    'the briefing label' => ['6.2 km done as planned, intent hit.', true],
    'a spaced enum after intent' => ['intent: too hard.', true],
    'a field name' => ['your completed_km says 8.', true],
    'emphasis only' => ['that was *easy*, properly.', false],
    'a backtick' => ['kept it `easy`.', false],
    'a described hit' => ['8 easy km at 7:22/km, kept properly easy the whole way.', false],
    'hit as a verb' => ['you hit the tempo block right on 5:00/km.', false],
    'a described too-hard day' => ['10 against an easy 7, harder than the day asked for.', false],
])->with([OutcomeLabels::class => [OutcomeLabels::class]]);

function sentSystemPromptCarriesPlanVerdictRule(ClientFake $client): void
{
    $client->assertSent(Responses::class, fn (string $method, array $params): bool => str_contains((string) $params['input'][0]['content'], PlanVerdictRule::RULE));
}

it('PostRunSpeechNarrator re-asks a story whose title or body quotes a distance no tool returned', function (string $title, string $body): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $client = new ClientFake([
        fakeAzureResponse(speechAnswer($title, $body)),
        fakeAzureResponse(speechAnswer('5 km on a quiet morning', 'Nothing more to it.')),
    ]);

    expect(nonNull(NarrationContent::fromStored(postRunNarrator(fakeStructuredCaller($client))->generate($a, $d, 'easy')))->title)
        ->toBe('5 km on a quiet morning');
    $client->assertSent(Responses::class, fn (string $method, array $params): bool => str_contains((string) end($params['input'])['content'], 'Rewrite the title and body: it quotes 0.9, which no tool returned'));
})->with([
    'in the title' => ['0.9 km more than last time', 'A quiet morning.'],
    'in the body' => ['a quiet morning', '5 km, and 0.9 km more than last time.'],
]);

it('PostRunSpeechNarrator leaves the claims figures to the anchor gate, not the fact check', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => ['per_km' => [['km' => 1, 'pace' => '6:00']]]]);
    [$caller, $client] = capturingCaller(speechAnswer(claims: [
        ['anchor' => 'split:1', 'text' => 'Km 1 ran 0.9 km into the hill.', 'value' => '6:00/km', 'delta' => null],
    ]));

    expect(storedClaims(postRunNarrator($caller)->generate($a, $d->refresh(), 'easy')))->toHaveCount(1);
    $client->assertSent(Responses::class, 1);
});

it('PostRunSpeechNarrator re-asks a story that turns the day\'s verdict around', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    PlannedSession::factory()->for($a->user)->create([
        'date' => nonNull($d->start_date_local)->toDateString(),
        'session_type' => SessionType::Tempo,
        'intent_verdict' => IntentVerdict::TooHard,
    ]);
    $client = new ClientFake([
        fakeAzureResponse(speechAnswer('this one came in easier than written', 'The block sat calm.')),
        fakeAzureResponse(speechAnswer('the block ran past what it asked', 'The block ran hot.')),
    ]);

    expect(nonNull(NarrationContent::fromStored(postRunNarrator(fakeStructuredCaller($client))->generate($a, $d, 'overloaded')))->title)
        ->toBe('the block ran past what it asked');
});

it('hands the post-run story and the post-run brief the same plan verdict rule', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    [$speechCaller, $speechClient] = capturingCaller(speechAnswer());
    postRunNarrator($speechCaller)->generate($a, $d, 'easy');

    [$briefCaller, $briefClient] = capturingCaller(briefingAnswer('5 km logged, no plan for today.', 'Recovery is the rest of the day.'));
    bootMascotNarratorWithCaller($briefCaller)->generate($a->user, Carbon::today());

    sentSystemPromptCarriesPlanVerdictRule($speechClient);
    sentSystemPromptCarriesPlanVerdictRule($briefClient);
});

it('BriefingMascotVoiceNarrator re-asks a post-run block that labels the intent', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today(), 'distance' => 6_200]);
    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'status' => 'done',
        'prescribed_km' => 6.2,
        'intent_verdict' => 'hit',
        'intent_evidence' => ['pace_sec' => 449, 'ceiling_pace_sec' => 408, 'basis' => 'pace'],
    ]);
    $client = new ClientFake([
        fakeAzureResponse(briefingAnswer('6.2 km done as planned, intent hit.', 'Recovery is the rest of the day.', reading: 'just_ran')),
        fakeAzureResponse(briefingAnswer('6.2 km done as planned, and it stayed easy.', 'Recovery is the rest of the day.', reading: 'just_ran')),
    ]);

    expect(nonNull(NarrationContent::fromStored(bootMascotNarratorWithCaller(fakeStructuredCaller($client))->generate($user, Carbon::today())))->title)
        ->toBe('6.2 km done as planned, and it stayed easy.');
});

it('BriefingMascotVoiceNarrator re-asks a post-run block that labels the intent only in its body', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today(), 'distance' => 6_200]);
    $client = new ClientFake([
        fakeAzureResponse(briefingAnswer('6.2 km done, and it stayed easy.', 'The day came to intent hit, so recovery is the rest of it.', reading: 'just_ran')),
        fakeAzureResponse(briefingAnswer('6.2 km done, and it stayed easy.', 'Recovery is the rest of the day.', reading: 'just_ran')),
    ]);

    $stored = bootMascotNarratorWithCaller(fakeStructuredCaller($client))->generate($user, Carbon::today());

    expect(nonNull(NarrationContent::fromStored($stored))->body)->toBe('Recovery is the rest of the day.');
    $client->assertSent(Responses::class, fn (string $method, array $params): bool => str_contains((string) end($params['input'])['content'], 'Rewrite the title or body'));
});

it('BriefingMascotVoiceNarrator post-run prompt shows outcomes in words, not verdict labels', function (): void {
    $prompt = narratorConstant(BriefingMascotVoiceNarrator::class, 'POST_RUN_SYSTEM_PROMPT');

    expect($prompt)->toContain('and it stayed easy.')
        ->and($prompt)->not->toContain('planned, intent hit.')
        ->and($prompt)->not->toContain('intent too hard.');
});

/**
 * The model must NAME its one reading in the schema before writing. The enum is
 * the mechanism, so a family added to the prompt without a matching value would
 * be unpickable — and silently so.
 */
it('TrendReadNarrator offers exactly the readings each prompt describes and commits to one first', function (string $constant, string $schemaConstant, string $count): void {
    $schema = new ReflectionClass(TrendReadNarrator::class)->getConstant($schemaConstant);
    $prompt = narratorConstant(TrendReadNarrator::class, $constant);

    foreach (arrayOf(data_get($schema, 'reading.enum')) as $reading) {
        expect($reading)->toBeString();
        assert(is_string($reading));
        expect($prompt)->toContain("`{$reading}`:");
    }

    expect($prompt)->toContain('COMMIT FIRST')
        ->and($prompt)->toContain("The {$count}, and `reading` takes exactly one of these values");
})->with([
    'no race' => ['SYSTEM_PROMPT', 'READING_PROPERTY_SCHEMA', 'three'],
    'race' => ['RACE_SYSTEM_PROMPT', 'RACE_READING_PROPERTY_SCHEMA', 'five'],
]);

it('TrendReadNarrator no-race prompt offers no reading that has no per-week data or only a VDOT score behind it', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorConstant(TrendReadNarrator::class, 'SYSTEM_PROMPT'));

    expect($prompt)
        ->and($prompt)->not->toContain('`consistency`')
        ->and($prompt)->not->toContain('pace_arc')
        ->and($prompt)->not->toContain('vdot')
        ->and($prompt)->not->toContain('pace estimate')
        ->and(data_get(new ReflectionClass(TrendReadNarrator::class)->getConstant('READING_PROPERTY_SCHEMA'), 'reading.enum'))
        ->toBe(['volume_arc', 'load_direction', 'adherence']);
});

it('TrendReadNarrator race support_trend reads the direction and the gap, with no projection', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorConstant(TrendReadNarrator::class, 'RACE_SYSTEM_PROMPT'));

    expect($prompt)
        ->toContain('how big the gap to the target still is. No projection of where it ends up')
        ->and($prompt)->not->toContain('close the gap');
});

it('TrendReadNarrator prompts ask for a headline and a 200-250 word story in 2-3 connected paragraphs, at most 7 numbers, keeping the still-true rules', function (string $constant): void {
    $prompt = preg_replace('/\s+/', ' ', narratorConstant(TrendReadNarrator::class, $constant));

    expect($prompt)
        ->toContain('body (2-3 paragraphs, 200-250 words in total')
        ->toContain('2-3 paragraphs separated by one blank line')
        ->toContain('at least 3 connected sentences')
        ->toContain('one beat of the same reading')
        ->toContain('never one topic per paragraph like a checklist')
        ->toContain('max 7 numbers across title + body combined')
        ->toContain('NO PADDING')
        ->toContain('never to filler')
        ->toContain('long-term load is their running load averaged over about six weeks, running only')
        ->toContain('NULLS ARE UNKNOWN, NOT ZERO')
        ->toContain("Nothing here is a verdict on the user, it's a reading of the weeks")
        ->and($prompt)->not->toContain('the last 7 days')
        ->and($prompt)->not->toContain('description');
})->with(['no race' => ['SYSTEM_PROMPT'], 'race' => ['RACE_SYSTEM_PROMPT']]);

it('TrendReadNarrator race prompt answers on track for race day without forecasting a finish', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorConstant(TrendReadNarrator::class, 'RACE_SYSTEM_PROMPT'));

    expect($prompt)
        ->toContain('the answer to "on track for race day?"')
        ->toContain('Never forecast a finish time')
        ->toContain('gap.pct is only the size')
        ->toContain('dedicated_preparation false')
        ->toContain("never the race's name")
        ->toContain('- "eight weeks out, and the target still asks for more than the running shows."');
});

it('WeeklyRecapNarrator sends only the continuity line and reads the week', function (): void {
    $user = User::factory()->create();
    $snapshot = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);

    expect(array_keys(weeklyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($snapshot)))
        ->toBe(NarratorContinuity::CONTEXT_KEYS);
});

it('MonthlyRecapNarrator sends only the continuity line and reads the month', function (): void {
    $user = User::factory()->create();

    expect(array_keys(monthlyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($user, '2026-05')))
        ->toBe(NarratorContinuity::CONTEXT_KEYS);
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
        'elapsed_time' => 1500,
    ]);

    return RunCard::factory()->create([
        'activity_id' => $activity->id,
        'rarity' => 'rare',
        'special_move' => 'Pembara Sabar',
    ]);
}

it('CardFlavorNarrator returns flavor on valid JSON', function (): void {
    $card = cardFixture();
    $caller = fakeCaller(json_encode(['flavor' => 'Card epic!'], JSON_THROW_ON_ERROR));
    $narrator = cardFlavorNarrator($caller);
    expect($narrator->generate($card))->toBe('card epic!');
});

it('CardFlavorNarrator lowercases an ordinary first word and keeps an acronym', function (string $flavor, string $expected): void {
    $narrator = cardFlavorNarrator(fakeCaller(json_encode(['flavor' => $flavor], JSON_THROW_ON_ERROR)));

    expect($narrator->generate(cardFixture()))->toBe($expected);
})->with([
    'an ordinary word' => ['Your fastest 5k landed inside this one.', 'your fastest 5k landed inside this one.'],
    'an acronym' => ['HR stayed low the whole way.', 'HR stayed low the whole way.'],
]);

it('CardFlavorNarrator keeps the card\'s own names and a two-sentence flavor in sentence case', function (string $flavor): void {
    $card = cardFixture();
    $card->update(['badges' => ['negative_split']]);
    $narrator = cardFlavorNarrator(fakeCaller(json_encode(['flavor' => $flavor], JSON_THROW_ON_ERROR)));

    expect($narrator->generate($card))->toBe($flavor);
})->with([
    'the special move' => ['Pembara Sabar held the second half quicker than the first.'],
    'a badge name' => ['Negative Split, and in the rain.'],
    'two sentences' => ['Your fastest 5k landed inside this one. The Rare label is just the receipt.'],
]);

it('CardFlavorNarrator prompt writes its examples by sentence count', function (): void {
    $prompt = narratorPrompt(CardFlavorNarrator::class);

    expect($prompt)
        ->toContain('"your fastest 5k landed inside this one, and the Legendary label is just the')
        ->and($prompt)->not->toContain('this one. The Legendary')
        ->and($prompt)->not->toContain('8 degrees')
        ->and($prompt)->toContain('Right: "you held Z2 for 90%');
});

it('CardFlavorNarrator hands the card identity in the user message', function (): void {
    $card = cardFixture();
    [$caller, $client] = capturingCaller('{"flavor":"x"}');

    cardFlavorNarrator($caller)->generate($card);

    $identity = json_encode([...new CardIdentityTool($card)->handle([]), ...NarratorContinuity::fields([])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $client->assertSent(Responses::class, fn (string $method, array $params): bool => $params['input'][1]['content'] === $identity);
});

it('CardFlavorNarrator leaves the run reads optional and no longer offers the card identity as a tool', function (): void {
    $card = cardFixture();

    $names = array_column(
        cardFlavorNarrator(fakeCaller('{"flavor":"x"}'))
            ->toolbox($card)->definitions(),
        'name',
    );

    expect($names)->toBe([
        'get_run_summary',
        'get_km_splits',
        'get_weather',
        'get_effort_context',
        'get_personal_records',
        'get_planned_sessions',
    ]);
});

it('CardFlavorNarrator writes a never-detailed card from its identity in one step with no tools', function (): void {
    $card = cardFixture();
    nonNull($card->activity->detail)->delete();
    $card = $card->fresh();
    [$caller, $client] = capturingCaller('{"flavor":"x"}');

    cardFlavorNarrator($caller)->generate(nonNull($card));

    // Offering tools that can only answer null teaches the model to distrust them.
    assertOneStepWithContext($client, [...new CardIdentityTool(nonNull($card))->handle([]), ...NarratorContinuity::fields([])]);
});

it('CardFlavorNarrator throws on missing flavor key', function (): void {
    $card = cardFixture();
    $caller = fakeCaller(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = cardFlavorNarrator($caller);
    $narrator->generate($card);
})->throws(UnavailableException::class);

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
        'mood' => 'blazing',
        'created_at' => Carbon::parse('2026-05-12T08:00'),
    ]);

    $context = new MonthTotalsTool($user, $month)->handle([]);
    expect($context['month'])->toBe('2026-05');
    expect($context['total_runs'])->toBe(1);
    expect($context['total_distance_km'])->toBe(8.0);
    expect($context['longest_run_km'])->toBe(8.0);
    expect(data_get($context, 'mood_mix.0.mood'))->toBe('blazing');
    expect($context['pr_count'])->toBe(0);
    expect($context['weekly_distance_km'])->toBeArray();

});

it('MonthlyRecapNarrator stores its headline and story as JSON content, not the reading', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller(json_encode(['reading' => 'consistency', 'title' => 'four weeks, each longer.', 'body' => 'A steady month.'], JSON_THROW_ON_ERROR));

    $stored = monthlyRecapNarrator($caller)->generate($user, '2026-05');

    expect($stored)->toBe(json_encode(['title' => 'four weeks, each longer.', 'body' => 'A steady month.'], JSON_THROW_ON_ERROR))
        ->and(NarrationContent::fromStored($stored))->toEqual(new NarrationContent('four weeks, each longer.', 'A steady month.'));
});

it('MonthlyRecapNarrator stores a capitalised headline in lowercase and leaves the body alone', function (): void {
    $caller = fakeCaller(json_encode(['reading' => 'consistency', 'title' => 'Four weeks, each longer.', 'body' => 'A steady month.'], JSON_THROW_ON_ERROR));

    $stored = monthlyRecapNarrator($caller)->generate(User::factory()->create(), '2026-05');

    expect(NarrationContent::fromStored($stored))->toEqual(new NarrationContent('four weeks, each longer.', 'A steady month.'));
});

it('MonthlyRecapNarrator asks for reading, title and body, the reading picked from its seven angles, with no step override', function (): void {
    $user = User::factory()->create();
    [$caller, $client] = capturingCaller(json_encode(['reading' => 'plan', 'title' => 't', 'body' => 'b'], JSON_THROW_ON_ERROR));

    monthlyRecapNarrator($caller)->generate($user, '2026-05');

    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $schema = data_get($params, 'text.format.schema');

        return data_get($schema, 'required') === ['reading', 'title', 'body']
            && data_get($schema, 'properties.reading.enum') === ['vs_last_month', 'plan', 'standout_run', 'records', 'long_arc', 'load', 'consistency']
            && data_get($params, 'max_output_tokens') === 2500;
    });
});

it('MonthlyRecapNarrator throws on a missing body key', function (): void {
    monthlyRecapNarrator(fakeCaller(json_encode(['reading' => 'load', 'title' => 'x'], JSON_THROW_ON_ERROR)))->generate(User::factory()->create(), '2026-05');
})->throws(UnavailableException::class);

it('MonthlyRecapNarrator hands the model the month, its runs, its plan, its records and the six-week arc', function (): void {
    expect(array_column(monthlyRecapNarrator(fakeCaller('{}'))->toolbox(User::factory()->create(), '2026-05')->definitions(), 'name'))
        ->toBe(['get_month_totals', 'get_month_runs', 'get_planned_sessions', 'get_month_records', 'get_trend_range_totals']);
});

it('MonthlyRecapNarrator reads the six-week arc as it stood on the month last day, not today', function (): void {
    Carbon::setTestNow('2026-07-20 12:00:00');
    $user = User::factory()->create();
    ActivityDetail::factory()->for(Activity::factory()->for($user)->create())->create(['start_date_local' => '2026-05-31 07:00:00', 'distance' => 5000.0]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->create())->create(['start_date_local' => '2026-06-01 07:00:00', 'distance' => 9000.0]);

    $arc = json_decode(monthlyRecapNarrator(fakeCaller('{}'))->toolbox($user, '2026-05')->invoke('get_trend_range_totals', '{}'), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($arc, 'range'))->toBe('6w')
        ->and(data_get($arc, 'current.runs'))->toBe(1)
        ->and(data_get($arc, 'current.distance_km'))->toEqual(5.0);
    Carbon::setTestNow();
});

it('MonthTotalsTool counts the same analysed runs get_month_runs lists, never a stub or another athlete', function (): void {
    $user = User::factory()->create();
    foreach (['2026-05-02T07:00', '2026-05-31T23:59'] as $startedAt) {
        ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create(['start_date_local' => Carbon::parse($startedAt), 'distance' => 5000.0]);
    }
    ActivityDetail::factory()->for(Activity::factory()->for($user)->stub()->create())->create(['start_date_local' => Carbon::parse('2026-05-10T07:00'), 'distance' => 9000.0]);
    ActivityDetail::factory()->for(Activity::factory()->for(User::factory()->create())->analyzed()->create())->create(['start_date_local' => Carbon::parse('2026-05-11T07:00'), 'distance' => 9000.0]);

    $totals = new MonthTotalsTool($user, '2026-05')->handle([]);
    $runs = new MonthRunsTool($user, '2026-05', app(VerdictNarrator::class))->handle([])['runs'];

    expect($totals['total_runs'])->toBe(count(arrayOf($runs)))->toBe(2)
        ->and($totals['total_distance_km'])->toBe(10.0)
        ->and($totals['longest_run_km'])->toBe(5.0);
});

it('MonthTotalsTool reads the previous calendar month and its pace to compare against', function (): void {
    $user = User::factory()->create();
    foreach ([['2026-05-05T07:00', 10000.0, 3000], ['2026-05-20T07:00', 5000.0, 1500], ['2026-04-30T23:59', 20000.0, 7200], ['2026-04-01T00:00', 10000.0, 3600], ['2026-03-31T23:59', 50000.0, 9000]] as [$startedAt, $meters, $seconds]) {
        ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
            'start_date_local' => Carbon::parse($startedAt), 'distance' => $meters, 'elapsed_time' => $seconds, 'moving_time' => $seconds,
        ]);
    }

    $totals = new MonthTotalsTool($user, '2026-05')->handle([]);

    expect($totals)->toMatchArray([
        'total_runs' => 2,
        'total_distance_km' => 15.0,
        'pace_sec_per_km' => 300.0,
        'pace_formatted' => '5:00',
        'prev_runs' => 2,
        'prev_distance_km' => 30.0,
        'prev_pace_sec_per_km' => 360.0,
        'prev_pace_formatted' => '6:00',
    ]);
});

it('MonthTotalsTool leaves prev_* null when the previous month has no runs, and steps back across January', function (): void {
    $user = User::factory()->create();
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create(['start_date_local' => Carbon::parse('2026-05-05T07:00'), 'distance' => 5000.0]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create(['start_date_local' => Carbon::parse('2025-12-31T23:59'), 'distance' => 8000.0]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create(['start_date_local' => Carbon::parse('2026-01-05T07:00'), 'distance' => 5000.0]);

    expect(new MonthTotalsTool($user, '2026-05')->handle([]))->toMatchArray([
        'prev_runs' => null, 'prev_distance_km' => null, 'prev_pace_sec_per_km' => null, 'prev_pace_formatted' => null,
    ])
        ->and(new MonthTotalsTool($user, '2026-01')->handle([]))->toMatchArray(['prev_runs' => 1, 'prev_distance_km' => 8.0]);
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
    PersonalRecord::factory()->forActivity($activity)->create([
        'category' => '5km', 'set_at' => Carbon::parse('2026-05-19T06:30'),
    ]);

    $context = new MonthTotalsTool($user, $month)->handle([]);

    expect($context['pr_count'])->toBe(1)
        ->and(data_get($context, 'weekly_distance_km.0'))->toBe(6.0)
        ->and(data_get($context, 'weekly_distance_km.2'))->toBe(10.0);
});

it('MonthTotalsTool counts only the PRs get_month_records lists', function (): void {
    $user = User::factory()->create();
    foreach (['analyzed' => Activity::factory()->for($user)->analyzed(), 'stub' => Activity::factory()->for($user)->stub()] as $factory) {
        $activity = $factory->create();
        ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse('2026-05-12T06:00')]);
        PersonalRecord::factory()->forActivity($activity)->create(['set_at' => Carbon::parse('2026-05-12T06:30')]);
    }

    expect(new MonthTotalsTool($user, '2026-05')->handle([])['pr_count'])
        ->toBe(1)
        ->toBe(count(arrayOf(new MonthRecordsTool($user, '2026-05')->handle([])['personal_records'])));
});

it('MonthTotalsTool reads the long-term load arc from the month snapshots', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-03', 'ctl_42d' => 30.0, 'form_status' => 'optimal',
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-31', 'ctl_42d' => 38.0, 'form_status' => 'fresh',
    ]);

    $context = new MonthTotalsTool($user, '2026-05')->handle([]);

    expect($context['long_term_load'])->toMatchArray([
        'ctl_start' => 30.0,
        'ctl_end' => 38.0,
        'load_balance_end' => 'fresh',
    ]);
});

it('MonthTotalsTool leaves long-term load null when the month has no snapshots', function (): void {
    $user = User::factory()->create();

    $context = new MonthTotalsTool($user, '2026-05')->handle([]);

    expect($context['long_term_load'])->toBeNull();
});

it('MonthTotalsTool resolves a shorter month correctly when the clock sits on the 31st', function (): void {
    Carbon::setTestNow('2026-10-31');
    $user = User::factory()->create();

    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000.0,
        'start_date_local' => Carbon::parse('2026-09-01T07:00'),
    ]);

    $context = new MonthTotalsTool($user, '2026-09')->handle([]);

    expect($context['total_runs'])->toBe(1);
    expect($context['total_distance_km'])->toBe(5.0);

    Carbon::setTestNow();
});

it('MonthTotalsTool resolves February correctly when the clock sits on January 31st', function (): void {
    Carbon::setTestNow('2026-01-31');
    $user = User::factory()->create();

    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000.0,
        'start_date_local' => Carbon::parse('2026-02-01T07:00'),
    ]);

    $context = new MonthTotalsTool($user, '2026-02')->handle([]);

    expect($context['total_runs'])->toBe(1);
    expect($context['total_distance_km'])->toBe(5.0);

    Carbon::setTestNow();
});

it('MonthlyRecapNarrator feeds prev_narrative when the prior month recap is Done', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('You were consistent last month.')->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-04',
    ]);

    $context = monthlyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($user, '2026-05');

    expect($context['prev_narrative'])->toBe('You were consistent last month.');
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

    $context = monthlyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($user, '2026-05');

    expect($context['prev_narrative'])->toBeNull();
});

it('MonthlyRecapNarrator finds the correct prior month when the clock sits on the 31st', function (): void {
    Carbon::setTestNow('2026-01-31');
    $user = User::factory()->create();
    Analysis::factory()->done('January was the base build.')->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-01',
    ]);

    $context = monthlyRecapNarrator(fakeCaller('{"narrative":"x"}'))->context($user, '2026-02');

    expect($context['prev_narrative'])->toBe('January was the base build.');

    Carbon::setTestNow();
});

it('MonthlyRecapNarrator reads the plan window for a shorter month when the clock sits on the 31st', function (): void {
    Carbon::setTestNow('2026-10-31');
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-09-01']);

    $client = new ClientFake([
        fakeAzureToolCallResponse([['name' => 'get_planned_sessions']]),
        fakeAzureResponse('{"reading":"plan","title":"t","body":"b"}'),
    ]);

    monthlyRecapNarrator(fakeStructuredCaller($client))->generate($user, '2026-09');

    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $toolOutputs = array_filter($params['input'], fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output');

        return $toolOutputs !== [] && str_contains((string) reset($toolOutputs)['output'], '2026-09-01');
    });

    Carbon::setTestNow();
});

// ── ProfileVoiceNarrator ───────────────────────────────────────────

it('ProfileVoiceNarrator returns profile voice on valid JSON', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller(profileVoiceJson('You have run 6247.5 km. Strong.'));
    $narrator = new ProfileVoiceNarrator($caller, app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));
    expect($narrator->generate($user))->toBe('You have run 6247.5 km. Strong.');
});

it('ProfileVoiceNarrator builds context from user stats', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000.0,
        'start_date_local' => Carbon::parse('2026-05-12T07:00'),
    ]);

    $caller = fakeCaller(profileVoiceJson('x'));
    $narrator = new ProfileVoiceNarrator($caller, app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));

    $context = new LifetimeStatsTool($user->refresh(), Carbon::now(), app(LifetimeStats::class))->handle([]);
    expect($context['total_runs'])->toBe(1)
        ->and($context['total_km'])->toBe(5.0)
        ->and($context['longest_run_km'])->toBe(5.0)
        // 07:00 falls in the morning bucket; streak needs no snapshots so 0.
        ->and($context['favorite_time'])->toBe('morning')
        ->and($context['weekly_streak'])->toBe(0);
});

it('ProfileVoiceNarrator reads the weekly streak and the most common run time', function (): void {
    $user = User::factory()->create();
    // Two consecutive weeks with runs -> streak 2.
    foreach ([0, 1] as $weeksBack) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->subWeeks($weeksBack)->toDateString(),
            'runs' => 3,
        ]);
    }
    // Most runs at night.
    foreach (['2026-05-10T20:00', '2026-05-12T21:00', '2026-05-14T07:00'] as $when) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => 5000.0,
            'start_date_local' => Carbon::parse($when),
        ]);
    }

    $context = new LifetimeStatsTool($user->refresh(), Carbon::now(), app(LifetimeStats::class))->handle([]);

    expect($context['weekly_streak'])->toBe(2)
        ->and($context['favorite_time'])->toBe('night');
});

it('ProfileVoiceNarrator feeds the latest load_balance as the consistency spine', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'runs' => 3, 'form_status' => 'overreaching',
    ]);

    $context = new LifetimeStatsTool($user->refresh(), Carbon::now(), app(LifetimeStats::class))->handle([]);

    expect($context['load_balance'])->toBe('heavy');
});

it('ProfileVoiceNarrator throws on missing profile_voice key', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new ProfileVoiceNarrator($caller, app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));
    $narrator->generate($user);
})->throws(UnavailableException::class);

it('ProfileVoiceNarrator throws when the model skips its evidence slots', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller(json_encode(['profile_voice' => 'a paragraph with no committed evidence'], JSON_THROW_ON_ERROR));
    $narrator = new ProfileVoiceNarrator($caller, app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));
    $narrator->generate($user);
})->throws(UnavailableException::class, 'required fields');

/**
 * The paragraph's numbers are bounded by the schema, not by a prose rule. Two
 * slots exist, both are required, and the paragraph may quote nothing else.
 */
it('ProfileVoiceNarrator bounds its quotable numbers to two schema slots', function (): void {
    $user = User::factory()->create();
    [$caller, $client] = capturingCaller(profileVoiceJson('x'));

    new ProfileVoiceNarrator($caller, app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class))->generate($user);

    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $schema = $params['text']['format']['schema'];

        return $method === 'create'
            // Order is the commitment: the slots are generated before the prose.
            && $schema['required'] === ['evidence_primary', 'evidence_secondary', 'profile_voice']
            && array_keys($schema['properties']) === ['evidence_primary', 'evidence_secondary', 'profile_voice'];
    });

    expect(narratorPrompt(ProfileVoiceNarrator::class))
        ->toContain('COMMIT TO IT FIRST')
        ->toContain('delta_formatted')
        ->toContain('Take the direction from relation alone')
        ->and(narratorPrompt(ProfileVoiceNarrator::class))->not->toContain('a bigger delta means')
        ->and(narratorPrompt(ProfileVoiceNarrator::class))->not->toContain('a falling delta_sec')
        ->and(narratorPrompt(ProfileVoiceNarrator::class))->not->toContain('fine to use as evidence of consistency');
});

/**
 * Live output on 2026-09-10 filled both slots correctly and then quoted a
 * weekly_streak the slots did not hold, because the prompt carved the streak
 * out as free to mention. The carve-out is gone -- the streak is a slot
 * candidate like any other -- and the bound is now checked in code rather than
 * asked for in prose.
 */
it('QuotedFigures reads every figure format a paragraph can carry', function (string $figures): void {
    assert(is_a($figures, QuotedFigures::class, true));

    expect($figures::in('26:47 off the marathon, 1:07:12 for the half'))->toBe(['26:47', '1:07:12'])
        ->and($figures::in('6,247.50 km across 635 runs at 52% chill'))->toBe(['6247.5', '635', '52'])
        ->and($figures::in('19.2% and 5.2km and 11bpm'))->toBe(['19.2', '5.2', '11'])
        ->and($figures::in('no numbers here'))->toBe([]);
})->with([QuotedFigures::class => [QuotedFigures::class]]);

it('QuotedFigures counts a figure any allowed entry accounts for as allowed', function (): void {
    expect(QuotedFigures::outside('26:47 and 28 weeks', ['26:47 off the marathon', '28 weeks in a row']))->toBe([])
        ->and(QuotedFigures::outside('26:47 and 28 weeks', ['26:47 off the marathon']))->toBe(['28']);
});

it('ProfileVoiceNarrator lets the paragraph quote a mood percentage without a slot', function (): void {
    $user = User::factory()->create();
    $cutoff = Carbon::now()->subWeeks(2);

    foreach (['blazing', 'blazing', 'blazing', 'chill', 'gassed'] as $mood) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create(['start_date_local' => $cutoff->copy()->addDay()]);
        StoryLine::factory()->for($user)->create([
            'activity_id' => $activity->id,
            'mood' => $mood,
        ]);
    }

    $caller = fakeCaller(profileVoiceJson('blazing takes 60% of your runs, and 6247.5 km says why.'));
    $narrator = new ProfileVoiceNarrator($caller, app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));

    expect($narrator->generate($user->refresh()))->toBe('blazing takes 60% of your runs, and 6247.5 km says why.');
});

it('ProfileVoiceNarrator lets the paragraph quote persona window and total figures without a slot', function (string $paragraph): void {
    $user = User::factory()->create();

    foreach (['blazing', 'blazing', 'blazing', 'chill', 'chill'] as $mood) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()->subWeeks(2)]);
        StoryLine::factory()->for($user)->create([
            'activity_id' => $activity->id,
            'mood' => $mood,
        ]);
    }

    $client = new ClientFake([
        fakeAzureResponse(profileVoiceJson($paragraph)),
        fakeAzureResponse(profileVoiceJson($paragraph)),
    ]);
    $narrator = new ProfileVoiceNarrator(fakeStructuredCaller($client), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));

    expect($narrator->generate($user))->toBe($paragraph);
})->with([
    'full window' => 'over the last 12 weeks, blazing led the mix.',
    'half window' => 'the last 6 weeks leaned blazing.',
    'returned total' => 'across 5 runs, blazing led the mix.',
]);

it('ProfileVoiceNarrator re-asks once when the paragraph quotes a figure no slot holds', function (): void {
    $user = User::factory()->create();
    $client = new ClientFake([
        fakeAzureResponse(profileVoiceJson('6247.5 km, and a 28 week streak behind it.')),
        fakeAzureResponse(profileVoiceJson('6247.5 km, and the habit behind it.')),
    ]);
    $narrator = new ProfileVoiceNarrator(fakeStructuredCaller($client), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));

    expect($narrator->generate($user))->toBe('6247.5 km, and the habit behind it.');

    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $last = end($params['input']);

        return $method === 'create'
            && is_array($last)
            && ($last['role'] ?? null) === 'user'
            && str_contains(Arr::string($last, 'content'), 'The paragraph quotes 28')
            && str_contains(Arr::string($last, 'content'), '6247.5 km');
    });
});

it('ProfileVoiceNarrator throws when the rewrite leaks a figure too', function (): void {
    $user = User::factory()->create();
    $client = new ClientFake([
        fakeAzureResponse(profileVoiceJson('6247.5 km, and a 28 week streak behind it.')),
        fakeAzureResponse(profileVoiceJson('6247.5 km, and 41 sessions behind it.')),
    ]);
    $narrator = new ProfileVoiceNarrator(fakeStructuredCaller($client), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));

    $narrator->generate($user);
})->throws(UnavailableException::class, 'rejected twice');

it('ProfileVoiceNarrator feeds the four training paces derived from the runner VDOT', function (): void {
    $user = User::factory()->create();
    seedConfirmedEffort($user, 5000, 1200);
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 1200]);

    $context = new TrainingPacesTool($user->refresh(), Carbon::now(), app(VdotEstimator::class), app(TrainingPaceCalculator::class))->handle([]);

    expect($context['easy_pace_sec'])->toBeInt()
        ->and($context['marathon_pace_sec'])->toBeInt()
        ->and($context['threshold_pace_sec'])->toBeInt()
        ->and($context['interval_pace_sec'])->toBeInt();
});

it('ProfileVoiceNarrator leaves training paces null when the user has no VDOT-eligible PR', function (): void {
    $user = User::factory()->create();

    $context = new TrainingPacesTool($user->refresh(), Carbon::now(), app(VdotEstimator::class), app(TrainingPaceCalculator::class))->handle([]);

    expect($context['easy_pace_sec'])->toBeNull()
        ->and($context['marathon_pace_sec'])->toBeNull()
        ->and($context['threshold_pace_sec'])->toBeNull()
        ->and($context['interval_pace_sec'])->toBeNull();
});

// ── BriefingMascotVoiceNarrator ───────────────────────────────────────

function bootMascotNarrator(string $content): BriefingMascotVoiceNarrator
{
    return bootMascotNarratorWithCaller(fakeCaller($content));
}

function bootMascotNarratorWithCaller(StructuredChatCaller $caller): BriefingMascotVoiceNarrator
{
    return new BriefingMascotVoiceNarrator(
        app(Vibe::class),
        app(TrainingLoad::class),
        app(VerdictNarrator::class),
        $caller,
        app(RuleBasedNarrationFiller::class),
        app(ResolveRunBaselineAction::class),
        app(TrainingBaseline::class),
        app(VdotEstimator::class),
        app(TrainingPaceCalculator::class),
        app(DayAnchorResolver::class),
        app(CitationValidator::class),
    );
}

it('BriefingMascotVoiceNarrator returns the mascot voice on valid JSON', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->analyzed()->create();

    $narrator = bootMascotNarrator(briefingAnswer('a hook for today', 'Your km ticked up slightly. Good.'));

    expect($narrator->generate($user, Carbon::today()))
        ->toBe(json_encode(['title' => 'a hook for today', 'body' => 'Your km ticked up slightly. Good.'], JSON_THROW_ON_ERROR));
});

it('BriefingMascotVoiceNarrator stores a capitalised headline in lowercase and leaves the body alone', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->analyzed()->create();

    $stored = bootMascotNarrator(briefingAnswer('Easy 30 today, because Thursday was a tempo.', 'Thursday was a tempo. Keep it loose.'))->generate($user, Carbon::today());

    expect(NarrationContent::fromStored($stored))->toEqual(new NarrationContent('easy 30 today, because Thursday was a tempo.', 'Thursday was a tempo. Keep it loose.'));
});

it('BriefingMascotVoiceNarrator asks for reading, title, body and session type, the reading picked from the day\'s seven angles', function (): void {
    $user = User::factory()->create();
    [$caller, $client] = capturingCaller(briefingAnswer());

    bootMascotNarratorWithCaller($caller)->generate($user, Carbon::today());

    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $schema = data_get($params, 'text.format.schema');

        return data_get($schema, 'required') === ['reading', 'title', 'body', 'session_type']
            && data_get($schema, 'properties.reading.enum') === ['plan_reason', 'week_so_far', 'last_run', 'load', 'readiness', 'streak', 'pattern']
            && ! array_key_exists('mascot_voice', arrayOf(data_get($schema, 'properties')));
    });
});

it('BriefingMascotVoiceNarrator asks the post-run block for a subset of those readings plus just_ran', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today(), 'distance' => 5_000]);
    [$caller, $client] = capturingCaller(briefingAnswer('5 km done, no plan for today.', 'Recovery is the rest of the day.', reading: 'just_ran'));

    bootMascotNarratorWithCaller($caller)->generate($user, Carbon::today());

    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $schema = data_get($params, 'text.format.schema');

        return data_get($schema, 'required') === ['reading', 'title', 'body', 'session_type']
            && data_get($schema, 'properties.reading.enum') === ['just_ran', 'plan_reason', 'week_so_far', 'load', 'readiness', 'streak'];
    });
});

it('BriefingMascotVoiceNarrator keeps a citation the plan actually backs', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->analyzed()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);

    $narrator = bootMascotNarrator(briefingAnswer(body: 'So I am keeping this to [an easy run](session:today) today.'));

    expect(nonNull(NarrationContent::fromStored($narrator->generate($user, Carbon::today())))->body)
        ->toBe('So I am keeping this to [an easy run](session:today) today.');
});

it('BriefingMascotVoiceNarrator unwraps a citation the model put in the headline', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->analyzed()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);

    $stored = bootMascotNarrator(briefingAnswer('[an easy run](session:today) with a hook.', 'Keep it loose.'))->generate($user, Carbon::today());

    expect(nonNull(NarrationContent::fromStored($stored))->title)->toBe('an easy run with a hook.');
});

it('BriefingMascotVoiceNarrator switches to the post-run contract after detailed ingest', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 10_000,
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'status' => 'overreached',
        'prescribed_km' => 6.2,
        'compliance_score' => 161,
        'intent_verdict' => 'too_hard',
    ]);
    [$caller, $client] = capturingCaller(briefingAnswer('10.0 km done against 6.2 km planned.', 'Recovery is the rest of the day.', reading: 'just_ran'));

    bootMascotNarratorWithCaller($caller)->generate($user, Carbon::today());

    $client->assertSent(Responses::class, fn (string $method, array $params): bool => $method === 'create'
        && str_contains((string) $params['input'][0]['content'], 'post-run block')
        && str_contains((string) $params['input'][0]['content'], 'Do not offer another run today')
        && str_contains((string) $params['input'][1]['content'], '"ran_today":true'));
});

it('BriefingMascotVoiceNarrator uses rule-based recovery if the model recommends another run', function (): void {
    $user = User::factory()->create();
    foreach ([5_000, 5_000] as $distance) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::today(),
            'distance' => $distance,
        ]);
    }
    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => 'tempo',
        'status' => 'partial',
        'prescribed_km' => 8.0,
        'compliance_score' => 63,
        'distance_score' => 63,
        'intent_verdict' => 'hit',
    ]);
    $narrator = bootMascotNarrator(briefingAnswer('go run again.', 'Go run again.', 'easy_only', 'just_ran'));

    $copy = $narrator->generate($user, Carbon::today());

    expect($copy)
        ->toContain('10.0 km logged, 5.0 km credited against 8.0 km planned')
        ->toMatch('/recovery/i')
        ->and($copy)->not->toContain('go run again');
});

/**
 * The model is told the plan exists, so it will cite one on a day the plan
 * does not cover. The words stay; the control does not.
 */
it('BriefingMascotVoiceNarrator unwraps a citation on a day no plan covers', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->analyzed()->create();

    $narrator = bootMascotNarrator(briefingAnswer(body: 'So I am keeping this to [an easy run](session:today) today.'));

    expect(nonNull(NarrationContent::fromStored($narrator->generate($user, Carbon::today())))->body)
        ->toBe('So I am keeping this to an easy run today.');
});

it('BriefingMascotVoiceNarrator throws on a missing body key', function (): void {
    $user = User::factory()->create();
    $narrator = bootMascotNarrator(json_encode(['reading' => 'load', 'title' => 'x', 'session_type' => 'rest'], JSON_THROW_ON_ERROR));
    $narrator->generate($user, Carbon::today());
})->throws(UnavailableException::class, 'missing required fields');

it('BriefingMascotVoiceNarrator clamps to a deterministic message when session_type exceeds readiness_ceiling', function (): void {
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);

    $narrator = bootMascotNarrator(briefingAnswer('a long run to close the week.', 'This week closes well on one long session.', 'quality_ok', 'week_so_far'));

    $voice = $narrator->generate($user, Carbon::today());

    expect($voice)->toContain('easy to moderate base run')
        ->and($voice)->not->toContain('Long run');
});

it('BriefingMascotVoiceNarrator clamped voices keep a lowercase headline over a body in sentence case', function (ReadinessCeiling $ceiling): void {
    $voice = new ReflectionMethod(BriefingMascotVoiceNarrator::class, 'clampedVoice')->invoke(null, $ceiling);
    assert(is_string($voice));
    [$headline, $body] = explode("\n\n", $voice);

    expect($headline)->toMatch('/^\p{Ll}/u')
        ->and($body)->toMatch('/^\p{Lu}/u')
        ->and(preg_match_all('/(?<=[.!?]) \p{Ll}/u', $body))->toBe(0);
})->with(ReadinessCeiling::cases());

it('BriefingMascotVoiceNarrator clamps when session_type is unparseable', function (): void {
    $user = User::factory()->create();

    $narrator = bootMascotNarrator(briefingAnswer('a free session today.', 'Free session today.', 'nonsense'));

    expect($narrator->generate($user, Carbon::today()))->not->toContain('Free session today.');
});

it('BriefingMascotVoiceNarrator feeds prev_narrative from the prior day Temari note when Done', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('Yesterday your km were up.')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-17',
    ]);

    $context = bootMascotNarrator('{}')->context($user, Carbon::parse('2026-05-18'));

    expect($context['prev_narrative'])->toBe('Yesterday your km were up.');
});

it('BriefingMascotVoiceNarrator omits prev_narrative when the prior day Temari note is not yet Done', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-17',
        'status' => AnalysisStatus::Pending,
    ]);

    $context = bootMascotNarrator('{}')->context($user, Carbon::parse('2026-05-18'));

    expect($context['prev_narrative'])->toBeNull();
});

// ── Prompt wording guards ────────────────────────────

/**
 * Read a narrator's private SYSTEM_PROMPT constant for wording assertions.
 * ProfileVoiceNarrator names its constant SYSTEM_PROMPT_TEMPLATE instead
 * (it's formatted with the mood vocabulary at call time), so fall back to that.
 */
function narratorPrompt(string $class): string
{
    assert(class_exists($class));
    $name = new ReflectionClass($class)->hasConstant('SYSTEM_PROMPT') ? 'SYSTEM_PROMPT' : 'SYSTEM_PROMPT_TEMPLATE';

    return narratorConstant($class, $name);
}

function narratorConstant(string $class, string $name): string
{
    expect(class_exists($class))->toBeTrue();
    assert(class_exists($class));
    $value = new ReflectionClass($class)->getConstant($name);
    expect($value)->toBeString();
    assert(is_string($value));

    return $value;
}

it('keeps diagnosis and fitness claims out of the load-reading narrator prompts', function (string $class): void {
    $prompt = narratorPrompt($class);

    expect($prompt)->not->toContain('injury-risk')
        ->and($prompt)->not->toContain('fitness is fading')
        ->and($prompt)->not->toContain('fitness slid')
        ->and($prompt)->not->toContain('fitness drifts')
        ->and($prompt)->not->toContain('overreaching')
        ->and($prompt)->not->toContain('fully back');
})->with([
    TrendReadNarrator::class => [TrendReadNarrator::class],
    MonthlyRecapNarrator::class => [MonthlyRecapNarrator::class],
    WeeklyRecapNarrator::class => [WeeklyRecapNarrator::class],
    BriefingMascotVoiceNarrator::class => [BriefingMascotVoiceNarrator::class],
]);

it('has the recap and briefing narrators read the three load balance states', function (string $class): void {
    expect(narratorPrompt($class))->toContain('load_balance')
        ->and(narratorPrompt($class))->not->toContain('form_status');
})->with([WeeklyRecapNarrator::class, MonthlyRecapNarrator::class, BriefingMascotVoiceNarrator::class]);

it('has the persona call the load numbers long-term load, short-term load and load balance', function (): void {
    expect(TemariPersona::SYSTEM_PROMPT)
        ->toContain('long-term load')
        ->toContain('short-term load')
        ->toContain('load balance')
        ->toContain('Never call them fitness, fatigue, readiness or overreaching')
        ->and(TemariPersona::SYSTEM_PROMPT)->not->toContain('overreaching, too much for too long');
});

/**
 * Absolute path under app/, derived from this file rather than app_path().
 *
 * Dataset closures are evaluated at collection time, before the container is
 * booted, so the helper functions below cannot reach Laravel's path helpers.
 */
function appSourcePath(string $relative): string
{
    return dirname(__DIR__, 5).'/app/'.$relative;
}

/**
 * Tool class basename => the `get_*` name it exposes to the model.
 *
 * Read from source rather than by instantiation: every tool is constructed with
 * a subject (an activity, a user and an as-of date, a card), which a static
 * check has no reason to build.
 *
 * @return array<string, string>
 */
function toolNamesByClass(): array
{
    $names = [];
    foreach (glob(appSourcePath('Services/AI/Agent/Tools/*.php')) ?: [] as $file) {
        $source = (string) file_get_contents($file);
        if (preg_match("/function name\(\): string\s*\{\s*return '([a-z_]+)';/", $source, $match) === 1) {
            $names[basename($file, '.php')] = $match[1];
        }
    }

    return $names;
}

/**
 * Each narrator paired with the tool names it can hand the model.
 *
 * Taken from the `new XxxTool(...)` sites in its source, so a conditionally
 * registered tool (CardFlavor varies its own by whether the run has detail)
 * counts as carried.
 *
 * @return array<string, array{0: string, 1: list<string>}>
 */
function narratorToolboxes(): array
{
    $byClass = toolNamesByClass();
    $cases = [];

    foreach (glob(appSourcePath('Services/AI/Narrators/*.php')) ?: [] as $file) {
        $source = (string) file_get_contents($file);
        if (! str_contains($source, 'AgentToolbox')) {
            continue;
        }

        preg_match_all('/new ([A-Za-z]+Tool)\(/', $source, $matches);
        $tools = array_values(array_unique(array_filter(
            array_map(fn (string $class): ?string => $byClass[$class] ?? null, $matches[1]),
        )));

        $narrator = basename($file, '.php');
        $cases[$narrator] = ['App\\Services\\AI\\Narrators\\'.$narrator, $tools];
    }

    return $cases;
}

/**
 * The `maxSteps: N` a narrator hands StructuredChatCaller, or null when it takes
 * the global `ai.agent.max_steps` default. Read from source so it cannot drift.
 */
function declaredMaxSteps(string $class): ?int
{
    $file = appSourcePath('Services/AI/Narrators/'.class_basename($class).'.php');
    preg_match('/maxSteps: (\d+)/', (string) file_get_contents($file), $matches);

    return isset($matches[1]) ? (int) $matches[1] : null;
}

/**
 * Every `kind: '...'` a narrator passes to StructuredChatCaller, read from source
 * so this cannot drift as narrators are added or renamed.
 *
 * @return list<string>
 */
function narratorKinds(): array
{
    $kinds = [];
    foreach (glob(appSourcePath('Services/AI/Narrators/*.php')) ?: [] as $file) {
        preg_match_all("/kind: '([a-z_]+)'/", (string) file_get_contents($file), $matches);
        foreach ($matches[1] as $kind) {
            $kinds[$kind] = true;
        }
    }

    return array_keys($kinds);
}

// A prompt naming a tool the narrator does not carry is not a typo: the model
// asks for it, AgentToolbox answers {"error":"unknown tool: ..."}, and the run
// burns a whole step plus a round trip recovering -- on every single generation.
// A since-deleted narrator did exactly that, telling the model to call
// get_card_identity while holding only get_featured_card.
it('no narrator prompt names a tool its own toolbox does not carry', function (string $class, array $tools): void {
    preg_match_all('/\bget_[a-z_]+/', narratorPrompt($class), $matches);

    $named = array_values(array_unique($matches[0]));
    expect(array_values(array_diff($named, $tools)))->toBe([]);
})->with(fn (): array => narratorToolboxes());

// A narrator only earns its own step ceiling when its toolbox is small enough
// that the tighter number still covers two full read passes: every tool takes
// no arguments and is worth calling once, so one pass is at most tools + 1
// turns, and the content-filter retry replays that pass on the same budget.
// Below 2 * (tools + 1) the retry would answer with no readings at all, which
// is a content change rather than a saving; at or above the global default the
// override buys nothing and would only raise the ceiling.
it('per-narrator step budgets cover two full read passes and only exist where they beat the default', function (): void {
    $default = config()->integer('ai.agent.max_steps');
    $declared = [];

    foreach (narratorToolboxes() as $narrator => [$class, $tools]) {
        $budget = declaredMaxSteps($class);
        if ($budget === null) {
            continue;
        }

        $declared[$narrator] = $budget;

        expect($budget)->toBeGreaterThanOrEqual(2 * (count($tools) + 1))
            ->and($budget)->toBeLessThan($default);
    }

    ksort($declared);

    expect($declared)->toBe([
        'TrendReadNarrator' => 8,
    ]);
});

// Model routing is env-owned: config/azure_openai.php maps each kind to its own
// AZURE_OPENAI_*_DEPLOYMENT var, falling back to the default. The map and the
// kind strings are kept in sync by hand, so a renamed kind would silently drop
// that narrator back to the default deployment with no error anywhere — and a
// key left behind reads as configurable when nothing consumes it. Both
// directions matter, so assert the two sets are identical.
it('the deployment map and the narrator kinds are the same set', function (): void {
    $kinds = narratorKinds();

    expect($kinds)->not->toBeEmpty()
        ->and(array_keys((array) config('azure_openai.narrators')))->toEqualCanonicalizing($kinds);
});

it('MonthlyRecapNarrator prompt skips the mood posture silently when mood_mix is empty', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(MonthlyRecapNarrator::class));

    expect($prompt)
        ->toContain('If mood_mix is empty, skip the posture and never mention that mood data is missing')
        ->and($prompt)->not->toContain('—');
});

it('MonthlyRecapNarrator prompt asks for a headline and a 200-280 word story in 2-3 connected paragraphs, with no sentence count', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(MonthlyRecapNarrator::class));

    expect($prompt)
        ->toContain('body (2-3 paragraphs, 200-280 words in total')
        ->toContain('2-3 paragraphs separated by one blank line')
        ->toContain('at least 3 connected sentences')
        ->toContain('one beat of the same reading')
        ->toContain('Never one sentence per paragraph')
        ->toContain('never one topic per paragraph like a checklist')
        ->and($prompt)->not->toContain('3-4 sentences')
        ->and($prompt)->not->toContain("don't ramble")
        ->and($prompt)->not->toContain('maksimal 90 kata')
        ->and($prompt)->not->toContain('maksimal 100 kata');
});

it('MonthlyRecapNarrator prompt takes its length from the month, never from padding', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(MonthlyRecapNarrator::class));

    expect($prompt)
        ->toContain('WHERE THE LENGTH COMES FROM')
        ->toContain('NO PADDING')
        ->toContain('never to filler');
});

it('MonthlyRecapNarrator prompt names all five tools, caps numbers at seven and compares honestly', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(MonthlyRecapNarrator::class));

    expect($prompt)
        ->toContain('get_month_totals')
        ->toContain('get_month_runs')
        ->toContain('get_planned_sessions')
        ->toContain('get_month_records')
        ->toContain('get_trend_range_totals')
        ->toContain('max 7 numbers across title + body combined')
        ->toContain('for YOU TO READ')
        ->toContain('If prev_* is null')
        ->toContain("don't make up a number")
        ->and($prompt)->not->toContain('NEVER compare against a previous month')
        ->and($prompt)->not->toContain('max 3 numbers');
});

it('MonthlyRecapNarrator prompt treats a short final weekly bucket as a partial week', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(MonthlyRecapNarrator::class));

    expect($prompt)
        ->toContain('a final bucket of only one to three days is a partial week')
        ->toContain('never read it as a drop');
});

it('MonthlyRecapNarrator offers exactly the readings its prompt describes and commits to one first', function (): void {
    $schema = new ReflectionClass(MonthlyRecapNarrator::class)->getConstant('READING_PROPERTY_SCHEMA');
    $prompt = narratorPrompt(MonthlyRecapNarrator::class);

    expect(data_get($schema, 'reading.enum'))->toBe(['vs_last_month', 'plan', 'standout_run', 'records', 'long_arc', 'load', 'consistency']);

    foreach (arrayOf(data_get($schema, 'reading.enum')) as $reading) {
        expect($reading)->toBeString();
        assert(is_string($reading));
        expect($prompt)->toContain("`{$reading}`:");
    }

    expect($prompt)->toContain('COMMIT FIRST')
        ->and(preg_replace('/\s+/', ' ', $prompt))->toContain('still says in words which way the month moved');
});

it('WeeklyRecapNarrator prompt asks for a headline and a 200-280 word story in 2-3 connected paragraphs, with no sentence count', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(WeeklyRecapNarrator::class));

    expect($prompt)
        ->toContain('body (2-3 paragraphs, 200-280 words in total')
        ->toContain('2-3 paragraphs separated by one blank line')
        ->toContain('at least 3 connected sentences')
        ->toContain('one beat of the same reading')
        ->toContain('Never one sentence per paragraph')
        ->toContain('never one topic per paragraph like a checklist')
        ->and($prompt)->not->toContain('ONE paragraph')
        ->and($prompt)->not->toContain('no blank lines')
        ->and($prompt)->not->toContain('3-4 sentences')
        ->and($prompt)->not->toContain('maksimal 90 kata')
        ->and($prompt)->not->toContain('maksimal 100 kata')
        ->and($prompt)->not->toContain('Close with 1 observation');
});

it('WeeklyRecapNarrator prompt takes its length from the week, never from padding', function (): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt(WeeklyRecapNarrator::class));

    expect($prompt)
        ->toContain('WHERE THE LENGTH COMES FROM')
        ->toContain('by day and by kind')
        ->toContain('NO PADDING')
        ->toContain('never to filler');
});

it('WeeklyRecapNarrator offers exactly the readings its prompt describes and commits to one first', function (): void {
    $schema = new ReflectionClass(WeeklyRecapNarrator::class)->getConstant('READING_PROPERTY_SCHEMA');
    $prompt = narratorPrompt(WeeklyRecapNarrator::class);

    expect(data_get($schema, 'reading.enum'))->toBe(['vs_last_week', 'plan', 'standout_run', 'records', 'long_arc', 'load']);

    foreach (arrayOf(data_get($schema, 'reading.enum')) as $reading) {
        expect($reading)->toBeString();
        assert(is_string($reading));
        expect($prompt)->toContain("`{$reading}`:");
    }

    expect($prompt)->toContain('COMMIT FIRST')
        ->and(preg_replace('/\s+/', ' ', $prompt))->toContain('still says in words which way the week moved');
});

it('WeeklyRecapNarrator prompt caps the number count so the recap stops reading as a table', function (): void {
    // Prod shipped ten numbers across four sentences: the field list read as a
    // menu to recite, and "sebutkan 1-2 angka" was no ceiling against it.
    $prompt = narratorPrompt(WeeklyRecapNarrator::class);

    expect($prompt)
        ->toContain('max 7 numbers across title + body combined')
        ->toContain('for YOU TO READ')
        ->toContain("That's a table, not a story.")
        ->and($prompt)->not->toContain('max 3 numbers')
        ->and($prompt)->not->toContain('Sebutkan 1-2');
});

it('CardFlavorNarrator prompt refuses badge and move names stitched together', function (): void {
    // Prod shipped "dapet badge Z2 Master, dibawa oleh special move Calm &
    // Steady" -- two labels joined by a connective, with nothing earned in view.
    $prompt = narratorPrompt(CardFlavorNarrator::class);

    expect($prompt)
        ->toContain('labels, not a story')
        ->toContain('carried by the Calm & Steady special move')
        ->toContain('glued together');
});

it('narrator prompt examples follow the casing rule: sentence case from two sentences, lowercase for a headline or one line', function (string $class, array $present, array $absent): void {
    $prompt = narratorPrompt($class);

    foreach ($present as $needle) {
        expect($prompt)->toContain($needle);
    }
    foreach ($absent as $needle) {
        expect($prompt)->not->toContain($needle);
    }
})->with([
    'post-run story titles stay lowercase, claim texts in sentence case' => [PostRunSpeechNarrator::class, ['- "first run in eleven days, and the legs remembered most of it."', '- "the last two km paid for the first three."', '"Km 5 came in at 5:18', 'You had something left.'], ['"First run in eleven days', '"km 5 came in', 'you had something left']],
    'profile voice' => [ProfileVoiceNarrator::class, ['"Six of your last ten sessions', 'Those two facts are the', '"Eleven weeks in a row with a run in them. That\'s not motivation'], ['"six of your last ten', 'those two facts', 'that\'s not motivation any more']],
    'profile voice examples stay inside its two evidence slots' => [ProfileVoiceNarrator::class, ['the stretch before that', 'come down 1:38 lately'], ['three hard ones', '1200 km of habit', 'across the last twelve weeks', '38 seconds', 'your 5k']],
    'briefing titles stay lowercase, the posture quote in sentence case' => [BriefingMascotVoiceNarrator::class, ['- "easy 30 today, because Thursday\'s tempo is still in the legs."', '- "a long run on the board, and the week is already three runs deep."', '"A light'], ['"Easy 30 today', '"A long run on the board', '"a light']],
    'weekly recap titles stay lowercase' => [WeeklyRecapNarrator::class, ['- "the scoreboard noticed: two runs this week, where last week had four."'], []],
    'monthly recap titles stay lowercase' => [MonthlyRecapNarrator::class, ['- "nothing in the month matched the long run in the second week."'], []],
    'trend read titles stay lowercase' => [TrendReadNarrator::class, ['- "six weeks bigger than the six before, and the long-term load followed."'], ['"Six weeks bigger']],
]);

// These four narrators' prompts must not train the model to front-load every
// tool into one turn; the loop supports more steps, so this is prompt wording,
// not code.
it('invites iterative tool follow-up instead of front-loading everything into one turn', function (string $class): void {
    // Heredoc line-wraps mid-phrase, so compare on whitespace-normalized text
    // rather than the raw string with its literal line breaks.
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt($class));

    expect($prompt)
        ->not->toContain('call several at once in a single turn')
        ->toContain("Don't front-load every tool into one turn out of habit");
})->with([
    PostRunSpeechNarrator::class => [PostRunSpeechNarrator::class],
    ProfileVoiceNarrator::class => [ProfileVoiceNarrator::class],
    CardFlavorNarrator::class => [CardFlavorNarrator::class],
    BriefingMascotVoiceNarrator::class => [BriefingMascotVoiceNarrator::class],
]);

// The prompt's "Scope"/data list must not read as if the numbers were already
// given: each of these three names its tool so the model fetches the data.
it('tells the model to call its tool for data that is never handed to it in context', function (string $class, string $toolName): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt($class));

    expect($prompt)
        ->toContain($toolName)
        ->toContain('handed to you up front');
})->with([
    'WeeklyRecapNarrator' => [WeeklyRecapNarrator::class, 'get_week_totals'],
    'MonthlyRecapNarrator' => [MonthlyRecapNarrator::class, 'get_month_totals'],
]);

it('CardFlavorNarrator prompt names rarity in English, not Indonesian', function (): void {
    $prompt = narratorPrompt(CardFlavorNarrator::class);

    expect($prompt)
        ->toContain('Common, Uncommon, Rare, Epic, Legendary')
        ->and($prompt)->not->toContain('Biasa')
        ->and($prompt)->not->toContain('Berkesan')
        ->and($prompt)->not->toContain('Langka')
        ->and($prompt)->not->toContain('Istimewa')
        ->and($prompt)->not->toContain('Legendaris');
});

// ── Structural guard: no swept narrator invites a past-run comparison ──

/**
 * No narrator states a past-run comparison; code renders it as its own fact
 * line next to the narration. Covers every narrator wired to past-you data
 * (PostRunSpeechNarrator, BriefingMascotVoiceNarrator); no other narrator's
 * toolbox or context() touches PastYouMatcher.
 */
it('never invites a past-run comparison and never wires the retired past-you tools', function (string $class): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt($class));

    expect($prompt)
        ->toContain('NEVER compare')
        ->and($prompt)->not->toContain('get_past_you')
        ->and($prompt)->not->toContain('get_latest_past_you');
})->with([
    'PostRunSpeechNarrator' => [PostRunSpeechNarrator::class],
    'BriefingMascotVoiceNarrator' => [BriefingMascotVoiceNarrator::class],
]);

it('never wires the retired past-you tools into a narrator toolbox', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $postRunNames = array_column(
        postRunNarrator(fakeCaller(speechAnswer()))->toolbox($a, $d)->definitions(),
        'name',
    );
    $briefingNames = array_column(
        app(BriefingMascotVoiceNarrator::class)->toolbox($a->user, Carbon::today())->definitions(),
        'name',
    );

    expect($postRunNames)->not->toContain('get_past_you')
        ->and($briefingNames)->not->toContain('get_latest_past_you');
});

it('tells the briefing and the weekly recap a session the athlete chose to run as written is their call', function (string $narrator): void {
    $prompt = narratorPrompt($narrator);

    expect($prompt)->toContain('overridden');
})->with([
    'briefing' => [BriefingMascotVoiceNarrator::class],
    'weekly recap' => [WeeklyRecapNarrator::class],
]);

// ── Continuity window: up to five previous narrations, newest first ──────

function jsonNarration(string $title, string $body): string
{
    return new NarrationContent($title, $body)->toStored();
}

it('PostRunSpeechNarrator passes the five newest earlier narrations, newest first, mixing JSON and plain rows', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $user = $a->user;
    foreach (range(1, 7) as $day) {
        priorActivityWithDoneAnalysis(
            $user,
            AnalysisType::PostRunSpeech,
            $day === 6 ? jsonNarration('headline six', 'Body six.') : "Plain narration for day {$day} with extra words to pass here",
            "2026-05-0{$day}",
        );
    }
    priorActivityWithDoneAnalysis($user, AnalysisType::PostRunSpeech, 'Later than the run itself.', Carbon::today()->addDays(30)->toDateString());
    priorActivityWithDoneAnalysis($user, AnalysisType::RunInsight, 'Wrong kind, ignored.', '2026-05-08');
    priorActivityWithDoneAnalysis(User::factory()->create(), AnalysisType::PostRunSpeech, 'Another athlete.', '2026-05-08');

    $context = postRunNarrator(fakeCaller(speechAnswer()))->context($a, $d->refresh(), 'blazing');

    expect($context['recent_openers'])->toBe([
        'Plain narration for day 7 with extra words to pass',
        'headline six',
        'Plain narration for day 5 with extra words to pass',
        'Plain narration for day 4 with extra words to pass',
        'Plain narration for day 3 with extra words to pass',
    ])
        ->and($context['prev_narrative'])->toBe('Plain narration for day 7 with extra words to pass here')
        ->and($context['prev_opener'])->toBe('Plain narration for day 7 with extra words to pass');
});

it('PostRunSpeechNarrator reads the previous narrations in two queries', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    foreach (range(1, 5) as $day) {
        priorActivityWithDoneAnalysis($a->user, AnalysisType::PostRunSpeech, "Narration {$day}", "2026-05-0{$day}");
    }
    $narrator = postRunNarrator(fakeCaller(speechAnswer()));
    $detail = $d->refresh();

    DB::enableQueryLog();
    $narrator->context($a, $detail, 'blazing');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(2);
});

it('WeeklyRecapNarrator passes up to five earlier recaps newest first and skips zero-run and not-Done weeks', function (): void {
    $user = User::factory()->create();
    $weeks = ['2026-04-05', '2026-04-12', '2026-04-19', '2026-04-26', '2026-05-03', '2026-05-10'];
    foreach ($weeks as $i => $weekEnding) {
        $snapshot = WeeklySnapshot::factory()->for($user)->create(['week_ending' => $weekEnding, 'runs' => 3]);
        Analysis::factory()->done($i === 4 ? jsonNarration('week five', 'Body five.') : "Recap of week {$i}.")->create([
            'subject_type' => WeeklySnapshot::class,
            'subject_id' => $snapshot->id,
            'analysis_type' => AnalysisType::WeeklyRecap,
            'discriminator' => null,
        ]);
    }
    $zeroRun = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-11', 'runs' => 0]);
    Analysis::factory()->done('Zero-run week recap.')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $zeroRun->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);
    $pending = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-12', 'runs' => 3]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $pending->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);
    $current = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $narrator = weeklyRecapNarrator(fakeCaller('{"narrative":"x"}'));
    $context = $narrator->context($current);

    expect($narrator->prevNarratives($current))->toBe([
        'Recap of week 5.',
        jsonNarration('week five', 'Body five.'),
        'Recap of week 3.',
        'Recap of week 2.',
        'Recap of week 1.',
    ])
        ->and($context['recent_openers'])->toBe(['Recap of week 5.', 'week five', 'Recap of week 3.', 'Recap of week 2.', 'Recap of week 1.'])
        ->and($context['prev_narrative'])->toBe('Recap of week 5.');
});

it('MonthlyRecapNarrator passes the Done recaps of the five months before, newest first', function (): void {
    $user = User::factory()->create();
    foreach (['2025-12' => 'December', '2026-01' => 'January', '2026-03' => 'March', '2026-04' => 'April', '2026-05' => 'May', '2026-06' => 'June'] as $month => $label) {
        Analysis::factory()->done("{$label} recap.")->create([
            'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => AnalysisType::MonthlyRecap,
            'discriminator' => $month,
        ]);
    }
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-02',
        'status' => AnalysisStatus::Pending,
    ]);

    $narrator = monthlyRecapNarrator(fakeCaller('{"narrative":"x"}'));

    expect($narrator->prevNarratives($user, '2026-07'))->toBe(['June recap.', 'May recap.', 'April recap.', 'March recap.'])
        ->and($narrator->prevNarratives($user, '2026-02'))->toBe(['January recap.', 'December recap.']);
});

it('BriefingMascotVoiceNarrator passes the five newest earlier daily notes, newest first', function (): void {
    $user = User::factory()->create();
    foreach (range(10, 18) as $day) {
        Analysis::factory()->done($day === 16 ? jsonNarration('sixteenth', 'Body.') : "Note of day {$day} that runs past the ten word opener window")->create([
            'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => AnalysisType::BriefingMascotVoice,
            'discriminator' => "2026-05-{$day}",
        ]);
    }

    $context = bootMascotNarrator('{}')->context($user, Carbon::parse('2026-05-17'));

    expect($context['recent_openers'])->toBe([
        'sixteenth',
        'Note of day 15 that runs past the ten word',
        'Note of day 14 that runs past the ten word',
        'Note of day 13 that runs past the ten word',
        'Note of day 12 that runs past the ten word',
    ]);
});

it('CardFlavorNarrator rotates against the athlete\'s five latest flavor lines on other cards and carries the continuity rule', function (): void {
    $card = cardFixture();
    $user = $card->activity->user;
    $flavorFor = function (User $owner, string $content, string $generatedAt) {
        $other = Activity::factory()->for($owner)->analyzed()->create();
        $otherCard = RunCard::factory()->create(['activity_id' => $other->id]);
        Analysis::factory()->done($content)->create([
            'subject_type' => RunCard::class,
            'subject_id' => $otherCard->id,
            'analysis_type' => AnalysisType::CardFlavor,
            'discriminator' => null,
            'generated_at' => $generatedAt,
        ]);
    };
    foreach (range(1, 6) as $i) {
        $flavorFor($user, "Flavor {$i}", "2026-05-0{$i} 10:00:00");
    }
    $flavorFor(User::factory()->create(), 'Another athlete flavor', '2026-05-09 10:00:00');
    Analysis::factory()->done('The card itself.')->create([
        'subject_type' => RunCard::class,
        'subject_id' => $card->id,
        'analysis_type' => AnalysisType::CardFlavor,
        'discriminator' => null,
        'generated_at' => '2026-05-08 10:00:00',
    ]);
    [$caller, $client] = capturingCaller('{"flavor":"x"}');

    cardFlavorNarrator($caller)->generate($card);

    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $context = json_decode(Arr::string($params['input'][1], 'content'), true);

        return is_array($context)
            && $context['recent_openers'] === ['Flavor 6', 'Flavor 5', 'Flavor 4', 'Flavor 3', 'Flavor 2']
            && str_contains(Arr::string($params['input'][0], 'content'), NarratorContinuity::RULE);
    });
});

it('PlanClampVoiceNarrator rotates against the earlier clamp lines and carries the continuity rule', function (): void {
    foreach (['2026-11-06' => 'you ran hard friday, so today is easy.', '2026-11-08' => jsonNarration('easy day', 'Body.'), '2026-11-09' => 'Same day, excluded.'] as $day => $content) {
        Analysis::factory()->done($content)->create([
            'subject_type' => AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE,
            'subject_id' => 7,
            'analysis_type' => AnalysisType::PlanClampVoice,
            'discriminator' => $day,
        ]);
    }
    [$caller, $client] = capturingCaller('{"voice":"x"}');

    new PlanClampVoiceNarrator($caller)->generate([
        'ceiling' => ReadinessCeiling::ModerateOk,
        'original' => SessionType::Interval,
        'clamped_to' => SessionType::Easy,
        'has_run_today' => false,
        'readiness_reasons' => ['demanding_session_within_24h'],
        'readiness_inputs' => [],
    ], 7, Carbon::parse('2026-11-09'));

    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $context = json_decode(Arr::string($params['input'][1], 'content'), true);

        return is_array($context)
            && $context['recent_openers'] === ['easy day', 'you ran hard friday, so today is easy.']
            && str_contains(Arr::string($params['input'][0], 'content'), NarratorContinuity::RULE);
    });
});
