<?php

declare(strict_types=1);

use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\TokenUsage;
use App\Models\User;
use App\Services\AI\Narrators\RunQuestionNarrator;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenAI\Testing\ClientFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/openai/deployments/x/chat/completions?api-version=2024-10-21');
    config()->set('azure_openai.api_key', 'fake-key');
    config()->set('azure_openai.deployment', 'x');
    config()->set('azure_openai.max_completion_tokens', 400);
});

function runQuestionNarrator(string $content): RunQuestionNarrator
{
    return new RunQuestionNarrator(
        fakeStructuredCaller(new ClientFake([fakeAzureResponse($content)])),
        app(TrainingLoad::class),
        app(ResolveRunBaselineAction::class),
        app(VdotEstimator::class),
        app(TrainingPaceCalculator::class),
        app(RelativeEffort::class),
    );
}

/** @return array{0: Activity, 1: ActivityDetail} */
function runQuestionFixture(?User $user = null, float $distance = 8000.0, ?array $streamSummary = null): array
{
    $activity = Activity::factory()->for($user ?? User::factory())->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-05-18 06:00:00'),
        'distance' => $distance,
        'moving_time' => 2400,
        'stream_summary' => $streamSummary,
    ]);

    return [$activity, $detail];
}

it('returns the answer from the structured payload', function (): void {
    [$activity, $detail] = runQuestionFixture();
    $narrator = runQuestionNarrator(json_encode(['answer' => 'your heart rate climbed 6 bpm while pace held.'], JSON_THROW_ON_ERROR));

    expect($narrator->generate($activity, $detail, 'why did my HR drift?'))
        ->toBe('your heart rate climbed 6 bpm while pace held.');
});

it('throws when the model answers without the answer key', function (): void {
    [$activity, $detail] = runQuestionFixture();
    runQuestionNarrator(json_encode(['other' => 'x'], JSON_THROW_ON_ERROR))
        ->generate($activity, $detail, 'why did my HR drift?');
})->throws(UnavailableException::class, 'missing answer');

it('meters the run into ai_token_usages under its own kind and the asking user', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = runQuestionFixture($user);

    runQuestionNarrator(json_encode(['answer' => 'steady all the way.'], JSON_THROW_ON_ERROR))
        ->generate($activity, $detail, 'was this even?');

    $usage = TokenUsage::query()->where('kind', 'run_question')->sole();
    expect($usage->user_id)->toBe($user->id)
        ->and($usage->total_tokens)->toBe(15)
        ->and($usage->steps)->toBe(1);
});

// ── Scoping: the toolbox is bound to one run, and nothing widens it ──────────

it('offers no tool that takes an identifier, so a question cannot name another run', function (): void {
    [$activity, $detail] = runQuestionFixture();
    $definitions = runQuestionNarrator('{}')->toolbox($activity, $detail)->definitions();

    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $definition) {
        expect($definition['parameters']['required'])->toBe([])
            ->and((array) $definition['parameters']['properties'])->toBe([]);
    }
});

it('serves this run even when the tool call carries another run id as arguments', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    [$mine, $myDetail] = runQuestionFixture($owner, distance: 8000.0);
    [$theirs] = runQuestionFixture($intruder, distance: 21_097.0);

    $toolbox = runQuestionNarrator('{}')->toolbox($mine, $myDetail);

    $forged = $toolbox->invoke('get_run_summary', json_encode([
        'activity_id' => $theirs->id,
        'user_id' => $intruder->id,
    ], JSON_THROW_ON_ERROR));

    expect($forged)->toContain('"distance_km":8')
        ->and($forged)->not->toContain('21.1');
});

it('cannot reach another run through an invented tool name', function (): void {
    [$activity, $detail] = runQuestionFixture();

    expect(runQuestionNarrator('{}')->toolbox($activity, $detail)->invoke('get_any_activity', '{"id": 999}'))
        ->toBe('{"error":"unknown tool: get_any_activity"}');
});

it('leaves the stream reads off a summary-state run instead of offering empty tools', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->summaryOnly()->create();
    $detail = ActivityDetail::factory()->for($activity)->create(['stream_summary' => null]);

    $names = array_column(runQuestionNarrator('{}')->toolbox($activity, $detail)->definitions(), 'name');

    expect($names)->toBe(['get_run_summary', 'get_training_load', 'get_recent_baseline', 'get_training_paces']);
});

it('offers the full stream reads once the run is detailed', function (): void {
    [$activity, $detail] = runQuestionFixture(streamSummary: ['per_km' => [['km' => 1, 'pace' => '5:30']]]);

    $names = array_column(runQuestionNarrator('{}')->toolbox($activity, $detail)->definitions(), 'name');

    expect($names)->toContain('get_km_splits')
        ->and($names)->toContain('get_hr_zones')
        ->and($names)->toContain('get_weather');
});
