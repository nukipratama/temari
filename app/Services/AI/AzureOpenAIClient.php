<?php

declare(strict_types=1);

namespace App\Services\AI;

use GuzzleHttp\Client;
use OpenAI;
use OpenAI\Contracts\ClientContract;

class AzureOpenAIClient
{
    public function client(): ClientContract
    {
        $endpoint = rtrim((string) config('azure_openai.uri'), '/');
        $timeout = (int) config('azure_openai.timeout');

        $factory = OpenAI::factory()
            ->withHttpHeader('api-key', (string) config('azure_openai.api_key'))
            ->withHttpHeader('User-Agent', 'TemanLari-Temari/1.0')
            // Azure's OpenAI-compatible v1 surface: an evergreen api-version (no
            // dated value to maintain); the deployment is selected by the request
            // body's `model` field, not the URL path.
            ->withQueryParam('api-version', 'preview');

        if ($endpoint !== '') {
            $factory = $factory->withBaseUri("{$endpoint}/openai/v1");
        }
        if ($timeout > 0) {
            $factory = $factory->withHttpClient(new Client(['timeout' => $timeout]));
        }

        return $factory->make();
    }

    /**
     * The Azure deployment (model) name for $kind, used as the request body's
     * `model`. The per-narrator override already falls back to the general
     * AZURE_OPENAI_DEPLOYMENT in config; a null/unmapped kind uses it directly.
     */
    public function deploymentFor(?string $kind): string
    {
        $perKind = $kind === null ? null : config("azure_openai.narrators.{$kind}");

        return (string) ($perKind ?: config('azure_openai.deployment'));
    }
}
