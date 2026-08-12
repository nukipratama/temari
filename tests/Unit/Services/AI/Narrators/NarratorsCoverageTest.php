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
use App\Services\AI\Agent\Tools\PersonalRecordTool;
use App\Services\AI\Agent\Tools\TrainingPacesTool;
use App\Services\AI\Agent\Tools\WeekTotalsTool;
use App\Services\AI\Narrators\AkuProfileVoiceNarrator;
use App\Services\AI\Narrators\BriefingMascotVoiceNarrator;
use App\Services\AI\Narrators\CardFlavorNarrator;
use App\Services\AI\Narrators\NarratorContinuity;
use App\Services\AI\Narrators\MonthlyRecapNarrator;
use App\Services\AI\Narrators\PostRunSpeechNarrator;
use App\Services\AI\Narrators\PrContextNarrator;
use App\Services\AI\Narrators\RunInsightNarrator;
use App\Services\AI\Narrators\WeeklyRecapNarrator;
use App\Services\Run\LifetimeStats;
use App\Services\Run\Metrics\RelativeEffort;
use App\Actions\Run\Metrics\ResolveRunBaselineAction;
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
    expect($narrator->generate($a, $d, 'blazing'))->toBe('Nice run today!');
});

it('PostRunSpeechNarrator throws on non-JSON', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $caller = fakeCaller('not json');
    $narrator = new PostRunSpeechNarrator($caller, app(PastYouMatcher::class));
    $narrator->generate($a, $d, 'blazing');
})->throws(UnavailableException::class, 'non-JSON');

it('PostRunSpeechNarrator throws on missing key', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $caller = fakeCaller(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new PostRunSpeechNarrator($caller, app(PastYouMatcher::class));
    $narrator->generate($a, $d, 'blazing');
})->throws(UnavailableException::class, 'missing speech');

it('PostRunSpeechNarrator does not fatal when the stream summary is null', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => null]);
    $caller = fakeCaller(json_encode(['speech' => 'Mantap'], JSON_THROW_ON_ERROR));
    $narrator = new PostRunSpeechNarrator($caller, app(PastYouMatcher::class));
    expect($narrator->generate($a, $d->fresh(), 'dim'))->toBe('Mantap');
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
    expect($narrator->generate($a, $d->fresh(), 'blazing'))->toBe('Base solid');
});

// The speech used to be handed all three insight blocks as prose to synthesize.
// On a page where all four render side by side, that made it a fourth telling of
// the same run. Its lens is what the other three structurally cannot hold -- the
// day around the run and where it sits in the athlete's history -- so the
// triplet no longer reaches it at all.
it('PostRunSpeechNarrator is not handed the insight blocks it used to retell', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $context = new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class))
        ->context($a, $d->fresh(), 'blazing');

    expect($context)->not->toHaveKey('insights');
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
    priorActivityWithDoneAnalysis($a->user, AnalysisType::PostRunSpeech, 'Lari kemarin easy banget.');

    $context = new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class))->context($a, $d->fresh(), 'blazing');

    expect($context['prev_narrative'])->toBe('Lari kemarin easy banget.')
        // prev_opener is the first few words, so the model can steer away from it.
        ->and($context['prev_opener'])->toBe('Lari kemarin easy banget.');
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

    $context = new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class))->context($a, $d->fresh(), 'blazing');

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

    $context = new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class))->context($a, $d->fresh(), 'blazing');

    expect($context['prev_opener'])->toBe('Masih nyambung dari sesi kemarin, kali ini penutupmu lebih hidup')
        ->and(str_word_count((string) $context['prev_opener']))->toBeLessThanOrEqual(10);
});

it('PostRunSpeechNarrator keeps only what no tool can serve in the context', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $context = new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class))
        ->context($a, $d->fresh(), 'blazing');

    // mood is the call's own argument, so it is not readable from anywhere.
    expect(array_keys($context))
        ->toBe(['mood', ...NarratorContinuity::CONTEXT_KEYS]);
});

it('PostRunSpeechNarrator is not offered the splits or zones its insights already interpret', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $names = array_column(
        new PostRunSpeechNarrator(fakeCaller('{"speech":"x"}'), app(PastYouMatcher::class))
            ->toolbox($a, $d)->definitions(),
        'name',
    );

    // Handing it the raw per-km table produced a fourth recitation of the same
    // "km 3 slowed, km 5 closed fastest" the three insight blocks already gave.
    expect($names)->toBe([
        'get_run_summary',
        'get_terrain',
        'get_weather',
        'get_personal_records',
        'get_past_you',
    ]);
});

// ── RunInsightNarrator ────────────────────────────────────────────────

it('RunInsightNarrator returns the claims payload on valid JSON, dropping only the invalid anchor', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => [
        'per_km' => [['km' => 1, 'pace' => '6:00'], ['km' => 2, 'pace' => '5:50'], ['km' => 3, 'pace' => '5:45']],
        'decoupling_pct' => 12.0,
    ]]);
    $caller = fakeCaller(json_encode(['claims' => [
        ['anchor' => 'split:2', 'text' => 'Km 2 was the fastest.', 'value' => '5:50/km', 'delta' => null],
        ['anchor' => 'metric:decoupling', 'text' => 'Decoupling ran high.', 'value' => '+12%', 'delta' => null],
        ['anchor' => 'split:99', 'text' => 'A km this run never ran.', 'value' => null, 'delta' => null],
    ]], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));

    $payload = $narrator->generate($a, $d->fresh());

    expect($payload['claims'])->toHaveCount(2)
        ->and($payload['claims'][0])->toBe(['anchor' => 'split:2', 'text' => 'Km 2 was the fastest.', 'value' => '5:50/km', 'delta' => null])
        ->and($payload['claims'][1]['anchor'])->toBe('metric:decoupling');
});

it('RunInsightNarrator throws on missing keys', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $caller = fakeCaller(json_encode(['something_else' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $narrator->generate($a, $d);
})->throws(UnavailableException::class);

it('RunInsightNarrator throws on non-JSON', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $caller = fakeCaller('not json');
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $narrator->generate($a, $d);
})->throws(UnavailableException::class, 'non-JSON');

it('RunInsightNarrator does not fatal when the stream summary is null, and renders no claims', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => null]);
    $caller = fakeCaller(json_encode(['claims' => [
        ['anchor' => 'metric:decoupling', 'text' => 'z', 'value' => null, 'delta' => null],
    ]], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));

    $payload = $narrator->generate($a, $d->fresh());

    // Every anchor kind is stream-summary-derived, so a run with no stream
    // summary at all can never resolve a claim -- same "nothing to say"
    // outcome as an all-invalid model response.
    expect($payload['claims'])->toBe([]);
});

// ── RunInsightNarrator anchor falsifiability ───────────────────────────

it('RunInsightNarrator survives a claim anchored to a split the run actually has', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => ['per_km' => [['km' => 1, 'pace' => '6:00'], ['km' => 2, 'pace' => '5:50']]]]);
    $caller = fakeCaller(json_encode(['claims' => [
        ['anchor' => 'split:2', 'text' => 'Km 2 was quick.', 'value' => '5:50/km', 'delta' => null],
    ]], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));

    expect($narrator->generate($a, $d->fresh())['claims'])->toHaveCount(1);
});

it('RunInsightNarrator drops a claim anchored to a split the run never recorded', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => ['per_km' => [['km' => 1, 'pace' => '6:00']]]]);
    $caller = fakeCaller(json_encode(['claims' => [
        ['anchor' => 'split:5', 'text' => 'A km that never happened.', 'value' => null, 'delta' => null],
    ]], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));

    expect($narrator->generate($a, $d->fresh())['claims'])->toBe([]);
});

it('RunInsightNarrator drops a claim anchored to a zone with no HR breakdown', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => ['per_km' => [['km' => 1, 'pace' => '6:00']]]]);
    $caller = fakeCaller(json_encode(['claims' => [
        ['anchor' => 'zone:z4', 'text' => 'Mostly Z4.', 'value' => null, 'delta' => null],
    ]], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));

    expect($narrator->generate($a, $d->fresh())['claims'])->toBe([]);
});

it('RunInsightNarrator survives a zone claim when the run has a real HR breakdown', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => ['time_in_zone_pct' => ['Z2' => 70, 'Z3' => 30]]]);
    $caller = fakeCaller(json_encode(['claims' => [
        ['anchor' => 'zone:z2', 'text' => 'Mostly Z2.', 'value' => '70%', 'delta' => null],
    ]], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));

    expect($narrator->generate($a, $d->fresh())['claims'])->toHaveCount(1);
});

it('RunInsightNarrator drops a metric claim whose reading was never computed for this run', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => ['per_km' => [['km' => 1, 'pace' => '6:00']]]]);
    $caller = fakeCaller(json_encode(['claims' => [
        ['anchor' => 'metric:hr_drift', 'text' => 'HR crept up.', 'value' => null, 'delta' => null],
    ]], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));

    expect($narrator->generate($a, $d->fresh())['claims'])->toBe([]);
});

it('RunInsightNarrator drops a claim whose anchor is not in the closed namespace at all', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => ['decoupling_pct' => 3.0]]);
    $caller = fakeCaller(json_encode(['claims' => [
        ['anchor' => 'weather:hot', 'text' => 'It was hot.', 'value' => null, 'delta' => null],
    ]], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));

    expect($narrator->generate($a, $d->fresh())['claims'])->toBe([]);
});

it('RunInsightNarrator renders nothing when every claim in the response is invalid', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => ['per_km' => [['km' => 1, 'pace' => '6:00']]]]);
    $caller = fakeCaller(json_encode(['claims' => [
        ['anchor' => 'split:9', 'text' => 'a', 'value' => null, 'delta' => null],
        ['anchor' => 'zone:z1', 'text' => 'b', 'value' => null, 'delta' => null],
        ['anchor' => 'metric:cadence_drop', 'text' => 'c', 'value' => null, 'delta' => null],
    ]], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));

    expect($narrator->generate($a, $d->fresh())['claims'])->toBe([]);
});

it('RunInsightNarrator caps claims at 3 even when the model returns more valid ones', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    $d->update(['stream_summary' => [
        'decoupling_pct' => 6.0,
        'hr_drift_bpm' => 5.0,
        'cadence_drop_spm' => 3.0,
        'pace_variability_sec' => 20.0,
    ]]);
    $caller = fakeCaller(json_encode(['claims' => [
        ['anchor' => 'metric:decoupling', 'text' => 'a', 'value' => null, 'delta' => null],
        ['anchor' => 'metric:hr_drift', 'text' => 'b', 'value' => null, 'delta' => null],
        ['anchor' => 'metric:cadence_drop', 'text' => 'c', 'value' => null, 'delta' => null],
        ['anchor' => 'metric:pace_variability', 'text' => 'd', 'value' => null, 'delta' => null],
    ]], JSON_THROW_ON_ERROR));
    $narrator = new RunInsightNarrator($caller, new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));

    expect($narrator->generate($a, $d->fresh())['claims'])->toHaveCount(3);
});

it('RunInsightNarrator prompt carries the quality-session framing so it stops assuming easy', function (): void {
    $prompt = narratorPrompt(RunInsightNarrator::class);

    expect($prompt)->toContain('session_intent')
        ->and($prompt)->toContain('QUALITY SESSIONS');
});

// Without this carve-out an interval session reads as sloppy pacing: the pace
// spread is wide by design, and pace_consistency bands it as "naik-turun" all
// the same, which the model then explains away as terrain or unstable effort.
it('RunInsightNarrator prompt exempts a lap-structured session from the pace-consistency reading', function (): void {
    $prompt = narratorPrompt(RunInsightNarrator::class);

    expect($prompt)->toContain('STRUCTURED SESSION')
        ->and($prompt)->toContain('rep_count')
        ->and($prompt)->toContain('recovery_sec')
        ->and($prompt)->toContain('warmup');
});

// The old fixed 3-slot schema asked for 2-3 paragraphs per slot; the adaptive
// claims schema replaces that with short, per-anchor observations instead.
it('RunInsightNarrator prompt keeps each claim short (1-2 sentences), not the old fixed-slot paragraphs', function (): void {
    $prompt = narratorPrompt(RunInsightNarrator::class);

    expect($prompt)->toContain('1-2 sentences')
        ->and($prompt)->not->toContain('2-3 short paragraphs');
});

// The old fixed 3-slot schema needed the prompt itself to redirect "technical"
// and "zones" around a missing sensor, forcing a full paragraph out of
// whatever WAS present. Adaptive claims don't have that problem: a metric or
// zone anchor with no real reading behind it is simply not a candidate, and
// the anchor resolver drops it even if the model tries anyway -- so the
// prompt only needs the general no-invention rule, not a per-slot redirect.
it('RunInsightNarrator prompt treats a missing reading as simply not a claim candidate, not something to redirect around', function (): void {
    $prompt = narratorPrompt(RunInsightNarrator::class);

    expect($prompt)->toContain('not a candidate for a claim')
        ->and($prompt)->not->toContain('IF CADENCE/HR/DECOUPLING IS MISSING')
        ->and($prompt)->not->toContain('IF THE ZONES ARE MISSING');
});

it('RunInsightNarrator feeds prev_narrative from the prior activity run-insight when Done', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();
    priorActivityWithDoneAnalysis($a->user, AnalysisType::RunInsight, 'Cadence kemarin 168, mulai membaik.');

    $narrator = new RunInsightNarrator(fakeCaller('{"claims":[]}'), new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $context = $narrator->context($a, $d->fresh());

    expect($context['prev_narrative'])->toBe('Cadence kemarin 168, mulai membaik.');
});

it('RunInsightNarrator leaves prev_narrative null when no prior run-insight is Done', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $narrator = new RunInsightNarrator(fakeCaller('{"claims":[]}'), new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $context = $narrator->context($a, $d->fresh());

    expect($context['prev_narrative'])->toBeNull();
});

it('RunInsightNarrator sends no run data in the context, only the continuity the filter retry must be able to strip', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $narrator = new RunInsightNarrator(fakeCaller('{"claims":[]}'), new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));

    expect(array_keys($narrator->context($a, $d->fresh())))
        ->toBe(NarratorContinuity::CONTEXT_KEYS);
});

it('RunInsightNarrator offers every run reading as a tool bound to this activity', function (): void {
    ['activity' => $a, 'detail' => $d] = postRunFixture();

    $narrator = new RunInsightNarrator(fakeCaller('{"claims":[]}'), new TrainingLoad(), new ResolveRunBaselineAction(), app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(RelativeEffort::class));
    $names = array_column($narrator->toolbox($a, $d)->definitions(), 'name');

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
    ]);
});

it('RunInsightNarrator prompt tells the model to fetch its own numbers and not invent the rest', function (): void {
    $prompt = narratorPrompt(RunInsightNarrator::class);

    expect($prompt)->toContain('Fetch them yourself')
        ->and($prompt)->toContain('NEVER make up');
});

it('BriefingMascotVoiceNarrator reads the day, the last run and the 28d baseline', function (): void {
    $user = User::factory()->create();
    $narrator = app(BriefingMascotVoiceNarrator::class);

    expect(array_column($narrator->toolbox($user, Carbon::today())->definitions(), 'name'))
        ->toBe(['get_week_state', 'get_recent_runs', 'get_training_load', 'get_latest_past_you', 'get_recent_baseline']);
});

it('BriefingMascotVoiceNarrator prompt tells the model to fetch its own numbers and not invent the rest', function (): void {
    $prompt = narratorPrompt(BriefingMascotVoiceNarrator::class);

    expect($prompt)->toContain('Fetch them yourself')
        ->and($prompt)->toContain('NEVER make up');
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
    expect($context['mood_mix'][0]['mood'])->toBe('blazing');
    expect($context['pr_count'])->toBe(0);
    expect($context['weekly_distance_km'])->toBeArray();

    expect(new MonthlyRecapNarrator(fakeCaller('{"narrative":"Bulan ini mostly blazing."}'))->generate($user, $month))
        ->toBe('Bulan ini mostly blazing.');
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

it('AkuProfileVoiceNarrator builds a mood-mix percent breakdown from story lines', function (): void {
    $user = User::factory()->create();
    $cutoff = Carbon::now()->subWeeks(11);

    foreach (['blazing', 'blazing', 'blazing', 'chill', 'gassed'] as $mood) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        StoryLine::factory()->for($user)->create([
            'activity_id' => $activity->id,
            'mood' => $mood,
            'created_at' => $cutoff->copy()->addDay(),
        ]);
    }

    $caller = fakeCaller(json_encode(['profile_voice' => 'Larimu lebih sering blazing.'], JSON_THROW_ON_ERROR));
    $narrator = new AkuProfileVoiceNarrator($caller, app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));

    $mix = $narrator->personaMix($user->fresh());
    $blazing = collect($mix)->firstWhere('mood', 'blazing');
    expect($blazing['mood'])->toBe('blazing');
    expect($blazing['count'])->toBe(3);
    expect($blazing['percent'])->toBe(60.0);
    expect($narrator->generate($user->fresh()))->toBe('Larimu lebih sering blazing.');
});

it('AkuProfileVoiceNarrator returns an empty mix for a user with no story lines', function (): void {
    $user = User::factory()->create();
    $caller = fakeCaller(json_encode(['profile_voice' => 'x'], JSON_THROW_ON_ERROR));
    $narrator = new AkuProfileVoiceNarrator($caller, app(VdotEstimator::class), app(TrainingPaceCalculator::class), app(ProgressionSeriesBuilder::class), app(LifetimeStats::class));

    expect($narrator->personaMix($user))->toBe([]);
});

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
        // 07:00 falls in the morning bucket; streak needs no snapshots so 0.
        ->and($context['favorite_time'])->toBe('morning')
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
    // Most runs at night.
    foreach (['2026-05-10T20:00', '2026-05-12T21:00', '2026-05-14T07:00'] as $when) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => 5000.0,
            'start_date_local' => Carbon::parse($when),
        ]);
    }

    $context = new LifetimeStatsTool($user->fresh(), Carbon::now(), app(LifetimeStats::class))->handle([]);

    expect($context['weekly_streak'])->toBe(2)
        ->and($context['favorite_time'])->toBe('night');
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
        app(ResolveRunBaselineAction::class),
    );
}

it('BriefingMascotVoiceNarrator returns the mascot voice on valid JSON', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->analyzed()->create();

    $narrator = bootMascotNarrator(json_encode([
        'mascot_voice' => 'Aku liat km kamu naik tipis, bagus.',
        'session_type' => 'rest',
    ], JSON_THROW_ON_ERROR));

    expect($narrator->generate($user, Carbon::today()))->toBe('Aku liat km kamu naik tipis, bagus.');
});

it('BriefingMascotVoiceNarrator throws on missing mascot_voice key', function (): void {
    $user = User::factory()->create();
    $narrator = bootMascotNarrator(json_encode(['session_type' => 'rest'], JSON_THROW_ON_ERROR));
    $narrator->generate($user, Carbon::today());
})->throws(UnavailableException::class, 'missing required fields');

it('BriefingMascotVoiceNarrator clamps to a deterministic message when session_type exceeds readiness_ceiling', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'form_status' => 'fatigued',
    ]);

    $narrator = bootMascotNarrator(json_encode([
        'mascot_voice' => "Long run santai, 8-12 km.\n\nMinggu ini enak ditutup satu sesi panjang.",
        'session_type' => 'quality_ok',
    ], JSON_THROW_ON_ERROR));

    $voice = $narrator->generate($user, Carbon::today());

    expect($voice)->toContain('Easy run, whatever feels comfortable today')
        ->and($voice)->not->toContain('Long run');
});

it('BriefingMascotVoiceNarrator clamps when session_type is unparseable', function (): void {
    $user = User::factory()->create();

    $narrator = bootMascotNarrator(json_encode([
        'mascot_voice' => 'Sesi bebas hari ini.',
        'session_type' => 'nonsense',
    ], JSON_THROW_ON_ERROR));

    expect($narrator->generate($user, Carbon::today()))->not->toBe('Sesi bebas hari ini.');
});

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

/**
 * Read a narrator's private SYSTEM_PROMPT constant for wording assertions.
 * AkuProfileVoiceNarrator names its constant SYSTEM_PROMPT_TEMPLATE instead
 * (it's formatted with the mood vocabulary at call time), so fall back to that.
 */
function narratorPrompt(string $class): string
{
    $reflection = new ReflectionClass($class);
    $name = $reflection->hasConstant('SYSTEM_PROMPT') ? 'SYSTEM_PROMPT' : 'SYSTEM_PROMPT_TEMPLATE';

    return (string) $reflection->getConstant($name);
}

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
 * registered tool (CardFlavor and PrContext both vary theirs by whether the run
 * has detail) counts as carried.
 *
 * @return array<string, array{0: class-string, 1: list<string>}>
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
// briefing_featured_kartu_voice did exactly that, telling the model to call
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
    $default = (int) config('ai.agent.max_steps');
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
        'BriefingFeaturedKartuVoiceNarrator' => 4,
        'MonthlyRecapNarrator' => 4,
        'PrContextNarrator' => 6,
        'WeeklyRecapNarrator' => 4,
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

it('MonthlyRecapNarrator prompt makes the mood step conditional on mood_mix', function (): void {
    $prompt = narratorPrompt(MonthlyRecapNarrator::class);

    expect($prompt)
        ->toContain('ONLY if mood_mix is populated')
        ->toContain('SKIP this step silently')
        ->not->toContain('—');
});

it('recap prompts give storytelling room (3-4 sentences, no rigid word cap)', function (string $narrator): void {
    $prompt = narratorPrompt($narrator);

    expect($prompt)->toContain('3-4 sentences')
        ->and($prompt)->toContain("don't ramble")
        ->and($prompt)->not->toContain('maksimal 90 kata')
        ->and($prompt)->not->toContain('maksimal 100 kata');
})->with([
    'weekly' => [WeeklyRecapNarrator::class],
    'monthly' => [MonthlyRecapNarrator::class],
]);

it('PostRunSpeechNarrator prompt hands the mechanics to the other three lenses', function (): void {
    // Predates the RunInsight consolidation (the "other three lenses" were
    // technical/splits/zones, now one adaptive block), but the reasoning
    // still holds: the two blocks render side by side, so a speech that
    // re-tells the pacing is visibly redundant. Telling it not to repeat had
    // already failed twice (its own prompt said so, and #423 took its tools);
    // the fix was giving it a lens of its own instead.
    $prompt = narratorPrompt(PostRunSpeechNarrator::class);

    expect($prompt)
        ->toContain('YOUR LENS')
        ->toContain("That's not your part.")
        ->toContain('NEVER dissect pacing')
        ->toContain('this run mattered');
});

it('WeeklyRecapNarrator prompt caps the number count so the recap stops reading as a table', function (): void {
    // Prod shipped ten numbers across four sentences: the field list read as a
    // menu to recite, and "sebutkan 1-2 angka" was no ceiling against it.
    $prompt = narratorPrompt(WeeklyRecapNarrator::class);

    expect($prompt)
        ->toContain('max 3 numbers across the ENTIRE output')
        ->toContain('for YOU TO READ')
        ->toContain("That's a table, not a story.")
        ->not->toContain('Sebutkan 1-2');
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

it('RunInsightNarrator prompt steers toward plain, natural phrasing while keeping run terms English', function (): void {
    $prompt = narratorPrompt(RunInsightNarrator::class);

    expect($prompt)
        ->toContain('LANGUAGE:')
        ->toContain('plain and conversational, not clinical')
        ->toContain('negative split')
        ->not->toContain('—');
});

// Superseded by this slice: the fixed 3-slot schema needed the prompt itself
// to redirect "technical"/"zones" around a missing sensor (validated twice
// against prod). Adaptive claims move that guarantee into code -- see "prompt
// treats a missing reading as simply not a claim candidate" above, plus the
// anchor-falsifiability tests, which now enforce it deterministically instead
// of trusting prompt wording.

// Prod data (830 real rows) showed these five narrators' prompts training the
// model to front-load every tool into one turn and stop, landing on exactly 2
// steps regardless of a 4-8 step budget -- confirmed not a maxSteps/AgentLoop
// bug (that loop genuinely supports more). The fix is prompt wording, not code.
it('invites iterative tool follow-up instead of front-loading everything into one turn', function (string $class): void {
    // Heredoc line-wraps mid-phrase, so compare on whitespace-normalized text
    // rather than the raw string with its literal line breaks.
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt($class));

    expect($prompt)
        ->not->toContain('call several at once in a single turn')
        ->toContain("Don't front-load every tool into one turn out of habit");
})->with([
    'RunInsightNarrator' => [RunInsightNarrator::class],
    'PostRunSpeechNarrator' => [PostRunSpeechNarrator::class],
    'AkuProfileVoiceNarrator' => [AkuProfileVoiceNarrator::class],
    'CardFlavorNarrator' => [CardFlavorNarrator::class],
    'BriefingMascotVoiceNarrator' => [BriefingMascotVoiceNarrator::class],
]);

// The same prod audit found a different problem for these three: 6-27% of
// live calls answered with zero tool calls despite having a toolbox, because
// the prompt's "Scope"/data list reads as if the numbers were already given
// rather than something to go fetch. None of the three previously named their
// tool anywhere in the prompt.
it('tells the model to call its tool for data that is never handed to it in context', function (string $class, string $toolName): void {
    $prompt = preg_replace('/\s+/', ' ', narratorPrompt($class));

    expect($prompt)
        ->toContain($toolName)
        ->toContain('handed to you up front');
})->with([
    'WeeklyRecapNarrator' => [WeeklyRecapNarrator::class, 'get_week_totals'],
    'MonthlyRecapNarrator' => [MonthlyRecapNarrator::class, 'get_month_totals'],
    'PrContextNarrator' => [PrContextNarrator::class, 'get_record'],
]);

it('CardFlavorNarrator prompt names rarity in English, not Indonesian', function (): void {
    $prompt = narratorPrompt(CardFlavorNarrator::class);

    expect($prompt)
        ->toContain('Common, Uncommon, Rare, Epic, Legendary')
        ->not->toContain('Biasa')
        ->not->toContain('Berkesan')
        ->not->toContain('Langka')
        ->not->toContain('Istimewa')
        ->not->toContain('Legendaris');
});
