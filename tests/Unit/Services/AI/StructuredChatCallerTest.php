<?php

declare(strict_types=1);

use App\Support\Config\AppConfig;
use OpenAI\Resources\Responses;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use App\Models\AI\TokenUsage;
use App\Services\AI\AzureConfigCircuitBreaker;
use App\Services\AI\AzureOpenAIClient;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\AI\TokenUsageRecorder;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Testing\ClientFake;
use Psr\Http\Client\ClientExceptionInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('azure_openai.deployment', 'gpt-test');
    config()->set('azure_openai.max_completion_tokens', 200);
});

/**
 * @param  array<string, int>|null  $usage  ['prompt_tokens' => …, 'completion_tokens' => …]
 */
function structuredCaller(string $content, ?array $usage = null, string $finishReason = 'stop'): StructuredChatCaller
{
    [$status, $reason] = $finishReason === 'length' ? ['incomplete', 'max_output_tokens'] : ['completed', null];
    $response = fakeAzureResponse($content, $status, $reason, $usage['prompt_tokens'] ?? 10, $usage['completion_tokens'] ?? 5);

    return fakeStructuredCaller(new ClientFake([$response]));
}

/**
 * @param  array<array-key, mixed>  $responses  queued ClientFake responses/throwables
 */
function callerWithResponses(array $responses): StructuredChatCaller
{
    return fakeStructuredCaller(new ClientFake($responses));
}

it('throws UnavailableException when structured output decodes to a non-object value', function (): void {
    structuredCaller('"just a string"')->call('kind', 'sys', [], 'schema', ['headline']);
})->throws(UnavailableException::class, 'structured output not an object');

it('throws UnavailableException when the model returns syntactically invalid JSON', function (): void {
    structuredCaller('{not valid json')->call('kind', 'sys', [], 'schema', ['headline']);
})->throws(UnavailableException::class, 'non-JSON');

it('throws UnavailableException when a required key is missing from the structured output', function (): void {
    structuredCaller(json_encode(['something_else' => 'x'], JSON_THROW_ON_ERROR))
        ->call('kind', 'sys', [], 'schema', ['headline']);
})->throws(UnavailableException::class, 'missing headline');

it('does not record token usage when the response is malformed', function (): void {
    expect(fn () => structuredCaller('{not valid json')->call('kind', 'sys', [], 'schema', ['headline']))
        ->toThrow(UnavailableException::class);

    expect(TokenUsage::query()->count())->toBe(0);
});

it('returns the decoded payload when all required keys are present', function (): void {
    $payload = structuredCaller(json_encode(['headline' => 'hi'], JSON_THROW_ON_ERROR))
        ->call('kind', 'sys', [], 'schema', ['headline']);

    expect($payload)->toBe(['headline' => 'hi']);
});

it('sends the narrator-tuned temperature in every request', function (): void {
    $client = new ClientFake([fakeAzureResponse(json_encode(['headline' => 'hi'], JSON_THROW_ON_ERROR))]);

    fakeStructuredCaller($client)->call('briefing', 'sys', [], 'schema', ['headline'], options: new ChatCallOptions(temperature: 0.42));

    $client->assertSent(
        Responses::class,
        fn (string $method, array $params): bool => $method === 'create' && $params['temperature'] === 0.42,
    );
});

it('records a token-usage row on successful call', function (): void {
    structuredCaller(
        json_encode(['headline' => 'hi'], JSON_THROW_ON_ERROR),
        ['prompt_tokens' => 120, 'completion_tokens' => 45, 'total_tokens' => 165],
    )->call('briefing', 'sys', [], 'schema', ['headline']);

    $row = TokenUsage::query()->first();
    expect($row)->not->toBeNull()
        ->and($row->kind)->toBe('briefing')
        ->and($row->prompt_tokens)->toBe(120)
        ->and($row->completion_tokens)->toBe(45)
        ->and($row->total_tokens)->toBe(165)
        ->and($row->model)->toBe('gpt-test')
        ->and($row->truncated)->toBeFalse()
        ->and($row->latency_ms)->toBeGreaterThanOrEqual(0)
        ->and($row->user_id)->toBeNull();
});

it('records user_id when passed by the narrator', function (): void {
    // ai_token_usages.user_id has no FK constraint, so a literal id proves the
    // same pass-through behavior without persisting a User row.
    structuredCaller(
        json_encode(['headline' => 'hi'], JSON_THROW_ON_ERROR),
        ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    )->call('briefing', 'sys', [], 'schema', ['headline'], options: new ChatCallOptions(userId: 42));

    expect(TokenUsage::query()->first()->user_id)->toBe(42);
});

it('flags truncated=true when the response stays length-truncated after the single retry', function (): void {
    Log::spy();

    $truncated = fakeAzureResponse(json_encode(['headline' => 'hi'], JSON_THROW_ON_ERROR), 'incomplete', 'max_output_tokens', 80, 200);

    // Both the initial call and the bumped-token retry come back truncated, so
    // the recorded usage row carries truncated=true for the final response.
    callerWithResponses([$truncated, $truncated])
        ->call('briefing', 'sys', [], 'schema', ['headline']);

    $row = TokenUsage::query()->first();
    expect($row->truncated)->toBeTrue();
    Log::shouldHaveReceived('warning')->with('narrator.ai.truncated', Mockery::any());
    Log::shouldHaveReceived('warning')->with('narrator.ai.truncated_retry', Mockery::any());
});

it('does not record usage when Azure call fails', function (): void {
    $azure = Mockery::mock(AzureOpenAIClient::class);
    $azure->shouldReceive('deploymentFor')->andReturn('gpt-test');
    $azure->shouldReceive('client')->andThrow(new RuntimeException('network down'));

    $caller = new StructuredChatCaller($azure, app(TokenUsageRecorder::class), app(AzureConfigCircuitBreaker::class));

    expect(fn () => $caller->call('briefing', 'sys', [], 'schema', ['headline']))
        ->toThrow(UnavailableException::class);

    expect(TokenUsage::query()->count())->toBe(0);
});

it('routes the per-kind client and records the resolved deployment', function (): void {
    $client = new ClientFake([fakeAzureResponse(json_encode(['headline' => 'hi'], JSON_THROW_ON_ERROR), 'completed', null, 10, 5)]);

    $azure = Mockery::mock(AzureOpenAIClient::class);
    // The kind routes through deploymentFor(): its resolved value is the request
    // body's `model` and the recorded usage row. The v1 client itself is the same
    // for every kind (deployment lives in the body, not the URL).
    $azure->shouldReceive('deploymentFor')->with('briefing')->andReturn('gpt-4o-briefing');
    $azure->shouldReceive('client')->andReturn($client);

    (new StructuredChatCaller($azure, app(TokenUsageRecorder::class), app(AzureConfigCircuitBreaker::class)))
        ->call('briefing', 'sys', [], 'schema', ['headline']);

    expect(TokenUsage::query()->first()->model)->toBe('gpt-4o-briefing');
});

it('records null deployment when the resolved deployment is empty', function (): void {
    $client = new ClientFake([fakeAzureResponse(json_encode(['headline' => 'hi'], JSON_THROW_ON_ERROR), 'completed', null, 1, 1)]);

    fakeStructuredCaller($client, deployment: '')->call('briefing', 'sys', [], 'schema', ['headline']);

    expect(TokenUsage::query()->first()->model)->toBeNull();
});

it('TokenUsageRecorder logs a warning when the DB insert throws', function (): void {
    Log::spy();

    Schema::drop('ai_token_usages');

    app(TokenUsageRecorder::class)->record('briefing', 10, 5, 15, 'gpt-test');

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('token_usage.record_failed', Mockery::on(fn (array $ctx) => $ctx['kind'] === 'briefing'));
});

// ── B1: transient vs terminal Azure-failure classification ────────────

it('maps a 429 rate-limit into a retryable TransientUpstreamException carrying Retry-After', function (): void {
    $response = new Psr7Response(429, ['Retry-After' => '17']);

    expect(fn () => callerWithResponses([new RateLimitException($response)])
        ->call('briefing', 'sys', [], 'schema', ['headline']))
        ->toThrow(function (TransientUpstreamException $e): void {
            expect($e->retryAfterSeconds)->toBe(17);
        });

    expect(TokenUsage::query()->count())->toBe(0);
});

it('leaves retryAfterSeconds null on a 429 without a numeric Retry-After header', function (): void {
    $response = new Psr7Response(429);

    expect(fn () => callerWithResponses([new RateLimitException($response)])
        ->call('briefing', 'sys', [], 'schema', ['headline']))
        ->toThrow(function (TransientUpstreamException $e): void {
            expect($e->retryAfterSeconds)->toBeNull();
        });
});

it('maps a 5xx ServerException into a TransientUpstreamException', function (): void {
    $response = new Psr7Response(503);

    expect(fn () => callerWithResponses([new ServerException($response)])
        ->call('briefing', 'sys', [], 'schema', ['headline']))
        ->toThrow(TransientUpstreamException::class, 'Azure OpenAI call failed');
});

it('maps a connection/timeout TransporterException into a TransientUpstreamException with no delay', function (): void {
    $clientException = new class ('read timed out') extends RuntimeException implements ClientExceptionInterface {};

    expect(fn () => callerWithResponses([new TransporterException($clientException)])
        ->call('briefing', 'sys', [], 'schema', ['headline']))
        ->toThrow(function (TransientUpstreamException $e): void {
            expect($e->retryAfterSeconds)->toBeNull();
        });
});

it('treats an ErrorException carrying a 429 status as transient', function (): void {
    $response = new Psr7Response(429, ['Retry-After' => '5']);
    $error = new ErrorException(['message' => 'rate limited', 'type' => 'rate_limit_exceeded'], $response);

    expect(fn () => callerWithResponses([$error])
        ->call('briefing', 'sys', [], 'schema', ['headline']))
        ->toThrow(function (TransientUpstreamException $e): void {
            expect($e->retryAfterSeconds)->toBe(5);
        });
});

it('keeps a permanent 4xx ErrorException terminal as UnavailableException', function (): void {
    $response = new Psr7Response(400);
    $error = new ErrorException(['message' => 'bad request', 'type' => 'invalid_request_error'], $response);

    expect(fn () => callerWithResponses([$error])
        ->call('briefing', 'sys', [], 'schema', ['headline']))
        ->toThrow(UnavailableException::class);
});

it('keeps a schema/JSON failure terminal even though the HTTP call succeeded', function (): void {
    expect(fn () => callerWithResponses([fakeAzureResponse('{not json')])
        ->call('briefing', 'sys', [], 'schema', ['headline']))
        ->toThrow(UnavailableException::class, 'non-JSON');
});

// ── E0-11: config/auth failures feed the Azure config circuit breaker ──

function configBreakerSnapshot(): array
{
    return (new AzureConfigCircuitBreaker(new AppConfig()))->snapshot();
}

it('counts a 401 auth failure toward the config circuit breaker', function (): void {
    $error = new ErrorException(['message' => 'unauthorized', 'type' => 'invalid_api_key'], new Psr7Response(401));

    expect(fn () => callerWithResponses([$error])->call('briefing', 'sys', [], 'schema', ['headline']))
        ->toThrow(UnavailableException::class);

    expect(configBreakerSnapshot())
        ->toMatchArray(['state' => AzureConfigCircuitBreaker::STATE_CLOSED, 'failures' => 1]);
});

it('counts a 403 auth failure toward the config circuit breaker', function (): void {
    $error = new ErrorException(['message' => 'forbidden', 'type' => 'access_denied'], new Psr7Response(403));

    expect(fn () => callerWithResponses([$error])->call('briefing', 'sys', [], 'schema', ['headline']))
        ->toThrow(UnavailableException::class);

    expect(configBreakerSnapshot()['failures'])->toBe(1);
});

it('counts a persistent connection/DNS TransporterException toward the config breaker', function (): void {
    $clientException = new class ('could not resolve host') extends RuntimeException implements ClientExceptionInterface {};

    expect(fn () => callerWithResponses([new TransporterException($clientException)])
        ->call('briefing', 'sys', [], 'schema', ['headline']))
        ->toThrow(TransientUpstreamException::class);

    expect(configBreakerSnapshot()['failures'])->toBe(1);
});

it('trips the config breaker open after three consecutive auth failures', function (): void {
    $error = fn (): ErrorException => new ErrorException(['message' => 'unauthorized', 'type' => 'invalid_api_key'], new Psr7Response(401));

    for ($i = 0; $i < 3; $i++) {
        expect(fn () => callerWithResponses([$error()])->call('briefing', 'sys', [], 'schema', ['headline']))
            ->toThrow(UnavailableException::class);
    }

    expect(configBreakerSnapshot()['state'])->toBe(AzureConfigCircuitBreaker::STATE_OPEN);
});

it('does not count a 429 rate-limit toward the config breaker', function (): void {
    expect(fn () => callerWithResponses([new RateLimitException(new Psr7Response(429))])
        ->call('briefing', 'sys', [], 'schema', ['headline']))
        ->toThrow(TransientUpstreamException::class);

    expect(configBreakerSnapshot())
        ->toMatchArray(['state' => AzureConfigCircuitBreaker::STATE_CLOSED, 'failures' => 0]);
});

it('resets the config-breaker streak on a successful call', function (): void {
    // Two auth failures build a streak, then a healthy call clears it.
    $error = fn (): ErrorException => new ErrorException(['message' => 'unauthorized', 'type' => 'invalid_api_key'], new Psr7Response(401));
    for ($i = 0; $i < 2; $i++) {
        expect(fn () => callerWithResponses([$error()])->call('briefing', 'sys', [], 'schema', ['headline']))
            ->toThrow(UnavailableException::class);
    }
    expect(configBreakerSnapshot()['failures'])->toBe(2);

    structuredCaller(json_encode(['headline' => 'hi'], JSON_THROW_ON_ERROR))
        ->call('briefing', 'sys', [], 'schema', ['headline']);

    expect(configBreakerSnapshot()['failures'])->toBe(0);
});

// ── B3: single higher-token retry on truncation ───────────────────────

it('retries once at a higher token cap when the first response is truncated', function (): void {
    Log::spy();

    // First response truncated, second (retry) is complete and valid.
    $caller = callerWithResponses([
        fakeAzureResponse(json_encode(['headline' => 'partial'], JSON_THROW_ON_ERROR), 'incomplete', 'max_output_tokens'),
        fakeAzureResponse(json_encode(['headline' => 'complete'], JSON_THROW_ON_ERROR)),
    ]);

    $payload = $caller->call('briefing', 'sys', [], 'schema', ['headline'], new ChatCallOptions(maxTokens: 200));

    expect($payload)->toBe(['headline' => 'complete']);
    Log::shouldHaveReceived('warning')
        ->once()
        ->with('narrator.ai.truncated_retry', Mockery::on(
            fn (array $ctx): bool => $ctx['max_output_tokens'] === 200 && $ctx['retry_max_output_tokens'] === 300,
        ));
});

it('retries truncation at most once and surfaces the still-truncated second response', function (): void {
    // Both responses truncated: caller must not loop, it accepts the 2nd as-is.
    $caller = callerWithResponses([
        fakeAzureResponse(json_encode(['headline' => 'one'], JSON_THROW_ON_ERROR), 'incomplete', 'max_output_tokens'),
        fakeAzureResponse(json_encode(['headline' => 'two'], JSON_THROW_ON_ERROR), 'incomplete', 'max_output_tokens'),
    ]);

    $payload = $caller->call('briefing', 'sys', [], 'schema', ['headline'], new ChatCallOptions(maxTokens: 200));

    // Only two responses were queued; a third call would throw "No fake responses left".
    expect($payload)->toBe(['headline' => 'two']);
});

it('does not retry truncation once the bumped cap would hit the ceiling', function (): void {
    Log::spy();

    // maxTokens already at the ceiling: 1.5x cannot exceed it, so no retry fires.
    $caller = callerWithResponses([
        fakeAzureResponse(json_encode(['headline' => 'capped'], JSON_THROW_ON_ERROR), 'incomplete', 'max_output_tokens'),
    ]);

    $payload = $caller->call('briefing', 'sys', [], 'schema', ['headline'], new ChatCallOptions(maxTokens: 4000));

    expect($payload)->toBe(['headline' => 'capped']);
    Log::shouldNotHaveReceived('warning', ['narrator.ai.truncated_retry', Mockery::any()]);
});
