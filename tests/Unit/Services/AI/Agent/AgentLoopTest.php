<?php

declare(strict_types=1);

use App\Exceptions\AI\ContentFilterException;
use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use App\Services\AI\Agent\AgentBudget;
use App\Services\AI\Agent\AgentLoop;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\AzureConfigCircuitBreaker;
use App\Services\AI\AzureOpenAIClient;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Support\Facades\Log;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Resources\Responses;
use OpenAI\Testing\ClientFake;

/**
 * @param  array<array-key, mixed>  $responses  queued ClientFake responses/throwables
 * @return array{0: AgentLoop, 1: ClientFake, 2: Mockery\MockInterface}
 */
function agentLoopWith(array $responses): array
{
    $client = new ClientFake($responses);
    $azure = Mockery::mock(AzureOpenAIClient::class);
    $azure->shouldReceive('client')->andReturn($client);
    $breaker = Mockery::mock(AzureConfigCircuitBreaker::class);
    $breaker->shouldReceive('recordSuccess')->andReturnNull();
    $breaker->shouldReceive('recordFailure')->andReturnNull();

    return [new AgentLoop($azure, $breaker), $client, $breaker];
}

/**
 * @return array<string, mixed>
 */
function agentLoopPayload(): array
{
    return [
        'model' => 'gpt-test',
        'input' => [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => '{}'],
        ],
        'max_output_tokens' => 200,
    ];
}

function agentLoopBudget(int $maxSteps = 8, int $maxTokens = 30_000): AgentBudget
{
    return new AgentBudget($maxSteps, $maxTokens);
}

// ── one-shot: no toolbox ──────────────────────────────────────────────

it('issues exactly one request and records one step when there is no toolbox', function (): void {
    [$loop, $client] = agentLoopWith([fakeAzureResponse('{}', 'completed', null, 40, 10)]);
    $budget = agentLoopBudget();

    [$response, $input] = $loop->converse('briefing', agentLoopPayload(), null, $budget, microtime(true));

    expect($response->outputText)->toBe('{}')
        ->and($input)->toBe(agentLoopPayload()['input'])
        ->and($budget->steps())->toBe(1)
        ->and($budget->inputTokens())->toBe(40)
        ->and($budget->outputTokens())->toBe(10);

    $client->assertSent(Responses::class, fn (string $method, array $params): bool => $method === 'create'
        && ! isset($params['tool_choice']));
});

it('folds the cached and reasoning breakdown of a turn into the budget', function (): void {
    [$loop] = agentLoopWith([fakeAzureResponse('{}', 'completed', null, 40, 10, 25, 4)]);
    $budget = agentLoopBudget();

    $loop->converse('briefing', agentLoopPayload(), null, $budget, microtime(true));

    expect($budget->cachedTokens())->toBe(25)->and($budget->reasoningTokens())->toBe(4);
});

// ── the tool loop ─────────────────────────────────────────────────────

it('feeds a tool reading back into the conversation before the model answers', function (): void {
    [$loop, $client] = agentLoopWith([
        fakeAzureToolCallResponse([['name' => 'get_thing']]),
        fakeAzureResponse('{}'),
    ]);
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => ['value' => 42])]);
    $budget = agentLoopBudget();

    [, $input] = $loop->converse('run_insight', agentLoopPayload(), $toolbox, $budget, microtime(true));

    $last = end($input);
    expect($budget->steps())->toBe(2)
        ->and($last['type'])->toBe('function_call_output')
        ->and($last['output'])->toBe('{"value":42}');

    $client->assertSent(Responses::class, fn (string $method, array $params): bool => $method === 'create'
        && $params['tool_choice'] === 'auto');
});

it('returns the first turn that asks for no tools', function (): void {
    [$loop] = agentLoopWith([fakeAzureResponse('{"headline":"straight to it"}')]);
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => [])]);
    $budget = agentLoopBudget();

    [$response] = $loop->converse('run_insight', agentLoopPayload(), $toolbox, $budget, microtime(true));

    expect($response->outputText)->toBe('{"headline":"straight to it"}')
        ->and($budget->steps())->toBe(1);
});

it('forbids tools once the step ceiling is hit and returns the forced answer', function (): void {
    Log::spy();

    [$loop, $client] = agentLoopWith([
        fakeAzureToolCallResponse([['name' => 'get_thing']]),
        fakeAzureResponse('{"headline":"capped"}'),
    ]);
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => ['value' => 1])]);
    $budget = agentLoopBudget(maxSteps: 1);

    [$response] = $loop->converse('run_insight', agentLoopPayload(), $toolbox, $budget, microtime(true));

    expect($response->outputText)->toBe('{"headline":"capped"}')
        ->and($budget->steps())->toBe(2);

    $client->assertSent(Responses::class, fn (string $method, array $params): bool => $method === 'create'
        && $params['tool_choice'] === 'none');

    Log::shouldHaveReceived('warning')->with('narrator.ai.agent_capped', Mockery::on(
        fn (array $ctx): bool => $ctx['reason'] === AgentBudget::REASON_STEPS && $ctx['kind'] === 'run_insight',
    ));
    Log::shouldHaveReceived('info')->with('narrator.ai.tool_step', Mockery::on(
        fn (array $ctx): bool => $ctx['tools'] === ['get_thing'],
    ));
});

it('keeps the turns it already billed when a later turn throws', function (): void {
    [$loop] = agentLoopWith([
        fakeAzureToolCallResponse([['name' => 'get_thing']], 100, 20),
        new RuntimeException('upstream blew up on turn two'),
    ]);
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => ['value' => 1])]);
    $budget = agentLoopBudget();

    expect(fn () => $loop->converse('run_insight', agentLoopPayload(), $toolbox, $budget, microtime(true)))
        ->toThrow(UnavailableException::class);

    expect($budget->steps())->toBe(1)
        ->and($budget->totalTokens())->toBe(120);
});

// ── the forced answer (truncation replay) ─────────────────────────────

it('replays the conversation at the raised cap with tools forbidden', function (): void {
    [$loop, $client] = agentLoopWith([fakeAzureResponse('{"headline":"full"}', 'completed', null, 50, 20)]);
    $payload = agentLoopPayload() + ['tools' => [['name' => 'get_thing']]];
    $input = [...agentLoopPayload()['input'], ['type' => 'function_call_output', 'call_id' => 'call_0', 'output' => '{}']];
    $budget = agentLoopBudget();

    $response = $loop->forceAnswer('run_insight', $payload, $input, 300, $budget, microtime(true));

    expect($response->outputText)->toBe('{"headline":"full"}')
        ->and($budget->steps())->toBe(1)
        ->and($budget->totalTokens())->toBe(70);

    $client->assertSent(Responses::class, fn (string $method, array $params): bool => $method === 'create'
        && $params['max_output_tokens'] === 300
        && $params['tool_choice'] === 'none'
        && $params['input'] === $input);
});

it('sends no tool_choice on a forced answer for a payload that carries no tools', function (): void {
    [$loop, $client] = agentLoopWith([fakeAzureResponse('{}')]);

    $loop->forceAnswer('briefing', agentLoopPayload(), agentLoopPayload()['input'], 300, agentLoopBudget(), microtime(true));

    $client->assertSent(Responses::class, fn (string $method, array $params): bool => $method === 'create'
        && ! isset($params['tool_choice']));
});

it('records no step when the forced answer itself throws', function (): void {
    [$loop] = agentLoopWith([new RuntimeException('gone')]);
    $budget = agentLoopBudget();

    expect(fn () => $loop->forceAnswer('briefing', agentLoopPayload(), agentLoopPayload()['input'], 300, $budget, microtime(true)))
        ->toThrow(UnavailableException::class);

    expect($budget->steps())->toBe(0);
});

// ── failure mapping and the config breaker ────────────────────────────

it('maps an upstream failure through the Azure failure taxonomy', function (): void {
    [$loop] = agentLoopWith([new ErrorException(['message' => 'slow down', 'type' => 'rate_limit_exceeded'], new Psr7Response(429))]);

    expect(fn () => $loop->converse('briefing', agentLoopPayload(), null, agentLoopBudget(), microtime(true)))
        ->toThrow(TransientUpstreamException::class, 'Azure OpenAI call failed');
});

it('counts a config/auth failure toward the breaker and logs the failed call', function (): void {
    Log::spy();

    [$loop, , $breaker] = agentLoopWith([new ErrorException(['message' => 'unauthorized', 'type' => 'invalid_api_key'], new Psr7Response(401))]);

    expect(fn () => $loop->converse('briefing', agentLoopPayload(), null, agentLoopBudget(), microtime(true)))
        ->toThrow(UnavailableException::class);

    $breaker->shouldHaveReceived('recordFailure')->once();
    $breaker->shouldNotHaveReceived('recordSuccess');
    Log::shouldHaveReceived('warning')->with('narrator.ai.call', Mockery::on(
        fn (array $ctx): bool => $ctx['status'] === 'fail' && $ctx['kind'] === 'briefing',
    ));
});

it('leaves the breaker alone for a transient rate limit', function (): void {
    [$loop, , $breaker] = agentLoopWith([new ErrorException(['message' => 'slow down', 'type' => 'rate_limit_exceeded'], new Psr7Response(429))]);

    expect(fn () => $loop->converse('briefing', agentLoopPayload(), null, agentLoopBudget(), microtime(true)))
        ->toThrow(TransientUpstreamException::class);

    $breaker->shouldNotHaveReceived('recordFailure');
});

it('resets the breaker streak on a call that reached Azure', function (): void {
    [$loop, , $breaker] = agentLoopWith([fakeAzureResponse('{}')]);

    $loop->converse('briefing', agentLoopPayload(), null, agentLoopBudget(), microtime(true));

    $breaker->shouldHaveReceived('recordSuccess')->once();
});

// ── output-side content filter: 200 + incomplete + content_filter ─────

it('throws a ContentFilterException for an output-filtered 200 response', function (): void {
    [$loop] = agentLoopWith([fakeAzureResponse('', 'incomplete', 'content_filter')]);

    expect(fn () => $loop->converse('briefing_mascot_voice', agentLoopPayload(), null, agentLoopBudget(), microtime(true)))
        ->toThrow(ContentFilterException::class, 'output filtered by content management policy');
});

it('records the step for an output-filtered turn, which Azure processed and billed', function (): void {
    [$loop] = agentLoopWith([fakeAzureResponse('', 'incomplete', 'content_filter', 60, 15)]);
    $budget = agentLoopBudget();

    expect(fn () => $loop->converse('briefing_mascot_voice', agentLoopPayload(), null, $budget, microtime(true)))
        ->toThrow(ContentFilterException::class);

    expect($budget->steps())->toBe(1)
        ->and($budget->inputTokens())->toBe(60)
        ->and($budget->outputTokens())->toBe(15);
});
