<?php

declare(strict_types=1);

use App\Services\AI\AzureOpenAIClient;
use OpenAI\Contracts\ClientContract;
use OpenAI\ValueObjects\Transporter\BaseUri;
use OpenAI\ValueObjects\Transporter\QueryParams;

beforeEach(function (): void {
    config()->set('azure_openai.api_key', 'fake');
    config()->set('azure_openai.timeout', 8);
    config()->set('azure_openai.uri', 'https://my-res.openai.azure.com');
    config()->set('azure_openai.deployment', 'gpt-default');
});

// ── client() ─────────────────────────────────────────────────────────────

it('builds an openai client for the v1 endpoint', function (): void {
    expect(new AzureOpenAIClient()->client())->toBeInstanceOf(ClientContract::class);
});

it('builds an openai client when the endpoint is empty (no baseUri)', function (): void {
    config()->set('azure_openai.uri', '');

    expect(new AzureOpenAIClient()->client())->toBeInstanceOf(ClientContract::class);
});

it('builds an openai client with no custom http client when timeout is 0', function (): void {
    config()->set('azure_openai.timeout', 0);

    expect(new AzureOpenAIClient()->client())->toBeInstanceOf(ClientContract::class);
});

it('targets the v1 GA surface with no api-version query parameter', function (): void {
    $client = new AzureOpenAIClient()->client();

    $transporter = new ReflectionProperty($client, 'transporter')->getValue($client);
    expect($transporter)->toBeObject();
    assert(is_object($transporter));
    $baseUri = new ReflectionProperty($transporter, 'baseUri')->getValue($transporter);
    $queryParams = new ReflectionProperty($transporter, 'queryParams')->getValue($transporter);
    expect($baseUri)->toBeInstanceOf(BaseUri::class)
        ->and($queryParams)->toBeInstanceOf(QueryParams::class);
    assert($baseUri instanceof BaseUri && $queryParams instanceof QueryParams);

    expect($baseUri->toString())->toBe('https://my-res.openai.azure.com/openai/v1/')
        ->and($queryParams->toArray())->not->toHaveKey('api-version');
});

// ── deploymentFor(): per-narrator override vs general fallback ────────────

it('prefers the per-kind override over the general deployment', function (): void {
    config()->set('azure_openai.deployment', 'gpt-general');
    config()->set('azure_openai.narrators.briefing', 'gpt-4o');

    expect(new AzureOpenAIClient()->deploymentFor('briefing'))->toBe('gpt-4o');
});

it('falls back to the general deployment when the override is empty', function (): void {
    config()->set('azure_openai.deployment', 'gpt-general');
    config()->set('azure_openai.narrators.briefing', '');

    expect(new AzureOpenAIClient()->deploymentFor('briefing'))->toBe('gpt-general');
});

it('uses the general deployment for an unmapped kind and for null', function (): void {
    expect(new AzureOpenAIClient()->deploymentFor('no-such-kind'))->toBe('gpt-default')
        ->and(new AzureOpenAIClient()->deploymentFor(null))->toBe('gpt-default');
});
