<?php

declare(strict_types=1);

use App\Services\AI\AzureOpenAIClient;
use OpenAI\Contracts\ClientContract;

beforeEach(function (): void {
    config()->set('azure_openai.api_key', 'fake');
    config()->set('azure_openai.timeout', 8);
    config()->set('azure_openai.uri', 'https://my-res.openai.azure.com');
    config()->set('azure_openai.deployment', 'gpt-default');
});

// ── client() ─────────────────────────────────────────────────────────────

it('builds an openai client for the v1 endpoint', function (): void {
    expect((new AzureOpenAIClient())->client())->toBeInstanceOf(ClientContract::class);
});

it('builds an openai client when the endpoint is empty (no baseUri)', function (): void {
    config()->set('azure_openai.uri', '');

    expect((new AzureOpenAIClient())->client())->toBeInstanceOf(ClientContract::class);
});

it('builds an openai client with no custom http client when timeout is 0', function (): void {
    config()->set('azure_openai.timeout', 0);

    expect((new AzureOpenAIClient())->client())->toBeInstanceOf(ClientContract::class);
});

// ── deploymentFor(): per-narrator override vs general fallback ────────────

it('uses the per-kind deployment override when one is set', function (): void {
    config()->set('azure_openai.narrators.briefing', 'gpt-4o');

    expect((new AzureOpenAIClient())->deploymentFor('briefing'))->toBe('gpt-4o');
});

it('prefers the per-kind override over the general deployment', function (): void {
    config()->set('azure_openai.deployment', 'gpt-general');
    config()->set('azure_openai.narrators.briefing', 'gpt-4o');

    expect((new AzureOpenAIClient())->deploymentFor('briefing'))->toBe('gpt-4o');
});

it('falls back to the general deployment when the override is empty', function (): void {
    config()->set('azure_openai.deployment', 'gpt-general');
    config()->set('azure_openai.narrators.briefing', '');

    expect((new AzureOpenAIClient())->deploymentFor('briefing'))->toBe('gpt-general');
});

it('uses the general deployment for an unmapped kind and for null', function (): void {
    expect((new AzureOpenAIClient())->deploymentFor('no-such-kind'))->toBe('gpt-default')
        ->and((new AzureOpenAIClient())->deploymentFor(null))->toBe('gpt-default');
});
