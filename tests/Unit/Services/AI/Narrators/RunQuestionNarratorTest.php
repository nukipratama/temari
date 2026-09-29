<?php

declare(strict_types=1);

use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\RunQuestion;
use App\Models\AI\TokenUsage;
use App\Models\User;
use App\Services\AI\Narrators\RunQuestionNarrator;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenAI\Resources\Responses;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\ClientFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/openai/deployments/x/chat/completions?api-version=2024-10-21');
    config()->set('azure_openai.api_key', 'fake-key');
    config()->set('azure_openai.deployment', 'x');
    config()->set('azure_openai.max_completion_tokens', 400);
});

/** @param  string|list<CreateResponse>  $responses */
function runQuestionNarrator(string|array $responses, ?ClientFake &$client = null): RunQuestionNarrator
{
    $client = new ClientFake(is_string($responses) ? [fakeAzureResponse($responses)] : $responses);

    return new RunQuestionNarrator(
        fakeStructuredCaller($client),
        app(TrainingLoad::class),
        app(ResolveRunBaselineAction::class),
        app(VdotEstimator::class),
        app(TrainingPaceCalculator::class),
        app(RelativeEffort::class),
        app(TrainingBaseline::class),
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
        'elapsed_time' => 2400,
        'stream_summary' => $streamSummary,
    ]);

    return [$activity, $detail];
}

function askedAbout(Activity $activity, string $question = 'why did my HR drift?'): RunQuestion
{
    return RunQuestion::factory()->create([
        'user_id' => $activity->user_id,
        'activity_id' => $activity->id,
        'question' => $question,
    ]);
}

it('returns the answer and the follow-ups from the structured payload', function (): void {
    [$activity, $detail] = runQuestionFixture();
    $narrator = runQuestionNarrator(json_encode([
        'answer' => 'your heart rate climbed 6 bpm while pace held.',
        'follow_ups' => ['what about km 5?', 'was it the heat?'],
    ], JSON_THROW_ON_ERROR));

    expect($narrator->generate($activity, $detail, askedAbout($activity)))->toBe([
        'answer' => 'your heart rate climbed 6 bpm while pace held.',
        'follow_ups' => ['what about km 5?', 'was it the heat?'],
    ]);
});

it('keeps at most two non-blank follow-ups', function (): void {
    [$activity, $detail] = runQuestionFixture();
    $narrator = runQuestionNarrator(json_encode([
        'answer' => 'steady.',
        'follow_ups' => ['  ', 'what about km 5? ', 'was it the heat?', 'and the cadence?'],
    ], JSON_THROW_ON_ERROR));

    expect($narrator->generate($activity, $detail, askedAbout($activity))['follow_ups'])
        ->toBe(['what about km 5?', 'was it the heat?']);
});

it('throws when the model answers without the required keys', function (): void {
    [$activity, $detail] = runQuestionFixture();
    runQuestionNarrator(json_encode(['answer' => 'x'], JSON_THROW_ON_ERROR))
        ->generate($activity, $detail, askedAbout($activity));
})->throws(UnavailableException::class, 'missing required fields');

it('answers a follow-up in the context of the earlier exchange it reads through get_thread', function (): void {
    $owner = User::factory()->create();
    [$activity, $detail] = runQuestionFixture($owner);
    [$otherRun] = runQuestionFixture($owner);
    RunQuestion::factory()->answered('km 4 was your slowest at 6:10/km.')->create([
        'user_id' => $owner->id, 'activity_id' => $activity->id, 'question' => 'which km cost me the most?',
    ]);
    RunQuestion::factory()->answered('elsewhere.')->create([
        'user_id' => $owner->id, 'activity_id' => $otherRun->id, 'question' => 'another run?',
    ]);
    $followUp = askedAbout($activity, 'why that one?');

    $narrator = runQuestionNarrator([
        fakeAzureToolCallResponse([['name' => 'get_thread', 'arguments' => json_encode(['activity_id' => $otherRun->id], JSON_THROW_ON_ERROR)]]),
        fakeAzureResponse(json_encode(['answer' => 'km 4 was the climb.', 'follow_ups' => []], JSON_THROW_ON_ERROR)),
    ], $client);

    expect($narrator->generate($activity, $detail, $followUp)['answer'])->toBe('km 4 was the climb.');

    $client->assertSent(Responses::class, function (string $method, array $params): bool {
        $outputs = array_column(array_filter($params['input'], fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'), 'output');

        return $outputs === [json_encode(['thread' => [[
            'question' => 'which km cost me the most?',
            'answer' => 'km 4 was your slowest at 6:10/km.',
        ]]], JSON_THROW_ON_ERROR)];
    });
});

it('meters the run into ai_token_usages under its own kind and the asking user', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = runQuestionFixture($user);

    runQuestionNarrator(json_encode(['answer' => 'steady all the way.', 'follow_ups' => []], JSON_THROW_ON_ERROR))
        ->generate($activity, $detail, askedAbout($activity, 'was this even?'));

    $usage = TokenUsage::query()->where('kind', 'run_question')->sole();
    expect($usage->user_id)->toBe($user->id)
        ->and($usage->total_tokens)->toBe(15)
        ->and($usage->steps)->toBe(1);
});

// ── Scoping: the toolbox is bound to one run, and nothing widens it ──────────

it('offers no tool that takes an identifier, so a question cannot name another run', function (): void {
    [$activity, $detail] = runQuestionFixture();
    $definitions = runQuestionNarrator('{}')->toolbox($activity, $detail, 1)->definitions();

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

    $toolbox = runQuestionNarrator('{}')->toolbox($mine, $myDetail, 1);

    $forged = $toolbox->invoke('get_run_summary', json_encode([
        'activity_id' => $theirs->id,
        'user_id' => $intruder->id,
    ], JSON_THROW_ON_ERROR));

    expect($forged)->toContain('"distance_km":8')
        ->and($forged)->not->toContain('21.1');
});

it('cannot reach another run through an invented tool name', function (): void {
    [$activity, $detail] = runQuestionFixture();

    expect(runQuestionNarrator('{}')->toolbox($activity, $detail, 1)->invoke('get_any_activity', '{"id": 999}'))
        ->toBe('{"error":"unknown tool: get_any_activity"}');
});

it('leaves the stream reads off a summary-state run instead of offering empty tools', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->summaryOnly()->create();
    $detail = ActivityDetail::factory()->for($activity)->create(['stream_summary' => null]);

    $names = array_column(runQuestionNarrator('{}')->toolbox($activity, $detail, 1)->definitions(), 'name');

    expect($names)->toBe(['get_run_summary', 'get_thread', 'get_training_load', 'get_recent_baseline', 'get_training_paces', 'get_planned_sessions']);
});

it('offers the full stream reads once the run is detailed', function (): void {
    [$activity, $detail] = runQuestionFixture(streamSummary: ['per_km' => [['km' => 1, 'pace' => '5:30']]]);

    $names = array_column(runQuestionNarrator('{}')->toolbox($activity, $detail, 1)->definitions(), 'name');

    expect($names)->toContain('get_thread')
        ->and($names)->toContain('get_km_splits')
        ->and($names)->toContain('get_hr_zones')
        ->and($names)->toContain('get_weather');
});
