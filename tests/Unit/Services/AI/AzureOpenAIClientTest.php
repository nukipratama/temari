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
