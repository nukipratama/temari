<?php

declare(strict_types=1);

use App\Services\AI\AzureOpenAIClient;
use OpenAI\Contracts\ClientContract;

beforeEach(function (): void {
    config()->set('azure_openai.api_key', 'fake');
    config()->set('azure_openai.timeout', 8);
});

it('builds an openai client when uri is empty (defaults baseUri)', function (): void {
    config()->set('azure_openai.uri', '');

    $client = (new AzureOpenAIClient())->client();
    expect($client)->toBeInstanceOf(ClientContract::class);
});

it('builds an openai client when a full Azure URI is provided', function (): void {
    config()->set('azure_openai.uri', 'https://my-resource.openai.azure.com/openai/deployments/gpt-x/chat/completions?api-version=2024-10-21');

    $client = (new AzureOpenAIClient())->client();
    expect($client)->toBeInstanceOf(ClientContract::class);
});

it('falls back to the default api-version when query string omits it', function (): void {
    config()->set('azure_openai.uri', 'https://my-resource.openai.azure.com/openai/deployments/gpt-x/chat/completions');
    config()->set('azure_openai.timeout', 0); // also exercises the "no custom http client" branch

    $client = (new AzureOpenAIClient())->client();
    expect($client)->toBeInstanceOf(ClientContract::class);
});

it('strips a trailing /responses path segment too', function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/openai/deployments/y/responses?api-version=2024-10-21');

    $client = (new AzureOpenAIClient())->client();
    expect($client)->toBeInstanceOf(ClientContract::class);
});

// ── Per-narrator routing: override vs fallback ───────────────────────────

beforeEach(function (): void {
    config()->set('azure_openai.deployment', 'gpt-default');
});

it('uses the per-kind deployment when that profile is fully configured', function (): void {
    config()->set('azure_openai.narrators.briefing', [
        'uri' => 'https://briefing.openai.azure.com/openai/deployments/gpt-4o/chat/completions',
        'api_key' => 'briefing-key',
        'deployment' => 'gpt-4o',
    ]);

    expect((new AzureOpenAIClient())->deploymentFor('briefing'))->toBe('gpt-4o');
});

it('falls back to the default deployment when the per-kind uri is empty', function (): void {
    config()->set('azure_openai.narrators.briefing', [
        'uri' => '',
        'api_key' => 'briefing-key',
        'deployment' => 'gpt-4o',
    ]);

    expect((new AzureOpenAIClient())->deploymentFor('briefing'))->toBe('gpt-default');
});

it('falls back to the default deployment when the per-kind api_key is empty', function (): void {
    config()->set('azure_openai.narrators.briefing', [
        'uri' => 'https://briefing.openai.azure.com/openai/deployments/gpt-4o/chat/completions',
        'api_key' => '',
        'deployment' => 'gpt-4o',
    ]);

    expect((new AzureOpenAIClient())->deploymentFor('briefing'))->toBe('gpt-default');
});

it('falls back to the default deployment for an unknown kind', function (): void {
    expect((new AzureOpenAIClient())->deploymentFor('no-such-kind'))->toBe('gpt-default');
});

it('uses the default deployment when kind is null', function (): void {
    expect((new AzureOpenAIClient())->deploymentFor(null))->toBe('gpt-default');
});

it('builds a client from the per-kind profile when fully configured', function (): void {
    config()->set('azure_openai.narrators.briefing', [
        'uri' => 'https://briefing.openai.azure.com/openai/deployments/gpt-4o/chat/completions?api-version=2024-10-21',
        'api_key' => 'briefing-key',
        'deployment' => 'gpt-4o',
    ]);

    expect((new AzureOpenAIClient())->client('briefing'))->toBeInstanceOf(ClientContract::class);
});
