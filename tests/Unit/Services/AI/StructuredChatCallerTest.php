<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Exceptions\AI\UnavailableException;
use App\Models\AI\TokenUsage;
use App\Services\AI\AzureOpenAIClient;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\AI\TokenUsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('azure_openai.deployment', 'gpt-test');
    config()->set('azure_openai.max_completion_tokens', 200);
});

/**
 * @param  array<string, int>|null  $usage  ['prompt_tokens' => …, 'completion_tokens' => …, 'total_tokens' => …]
 */
function structuredCaller(string $content, ?array $usage = null, string $finishReason = 'stop'): StructuredChatCaller
{
    $fakeArgs = [
        'choices' => [
            ['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => $finishReason],
        ],
    ];
    if ($usage !== null) {
        $fakeArgs['usage'] = $usage;
    }

    $client = new ClientFake([CreateResponse::fake($fakeArgs)]);
    $azure = Mockery::mock(AzureOpenAIClient::class);
    $azure->shouldReceive('client')->andReturn($client);

    return new StructuredChatCaller($azure, app(TokenUsageRecorder::class));
}

it('throws UnavailableException when structured output decodes to a non-object value', function (): void {
    structuredCaller('"just a string"')->call('kind', 'sys', [], 'schema', ['headline']);
})->throws(UnavailableException::class, 'structured output not an object');

it('returns the decoded payload when all required keys are present', function (): void {
    $payload = structuredCaller(json_encode(['headline' => 'hi'], JSON_THROW_ON_ERROR))
        ->call('kind', 'sys', [], 'schema', ['headline']);

    expect($payload)->toBe(['headline' => 'hi']);
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
    $user = User::factory()->create();

    structuredCaller(
        json_encode(['headline' => 'hi'], JSON_THROW_ON_ERROR),
        ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    )->call('briefing', 'sys', [], 'schema', ['headline'], options: new ChatCallOptions(userId: $user->id));

    expect(TokenUsage::query()->first()->user_id)->toBe($user->id);
});

it('flags truncated=true when finish_reason is length', function (): void {
    Log::spy();

    structuredCaller(
        json_encode(['headline' => 'hi'], JSON_THROW_ON_ERROR),
        ['prompt_tokens' => 80, 'completion_tokens' => 200, 'total_tokens' => 280],
        finishReason: 'length',
    )->call('briefing', 'sys', [], 'schema', ['headline']);

    $row = TokenUsage::query()->first();
    expect($row->truncated)->toBeTrue();
    Log::shouldHaveReceived('warning')->once()->with('narrator.ai.truncated', Mockery::any());
});

it('does not record usage when Azure call fails', function (): void {
    $azure = Mockery::mock(AzureOpenAIClient::class);
    $azure->shouldReceive('client')->andThrow(new RuntimeException('network down'));

    $caller = new StructuredChatCaller($azure, app(TokenUsageRecorder::class));

    expect(fn () => $caller->call('briefing', 'sys', [], 'schema', ['headline']))
        ->toThrow(UnavailableException::class);

    expect(TokenUsage::query()->count())->toBe(0);
});

it('TokenUsageRecorder logs a warning when the DB insert throws', function (): void {
    Log::spy();

    Schema::drop('ai_token_usages');

    app(TokenUsageRecorder::class)->record('briefing', 10, 5, 15, 'gpt-test');

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('token_usage.record_failed', Mockery::on(fn (array $ctx) => $ctx['kind'] === 'briefing'));
});
