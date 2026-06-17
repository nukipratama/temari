<?php

declare(strict_types=1);

namespace App\Services\AI;

use GuzzleHttp\Client;
use OpenAI;
use OpenAI\Contracts\ClientContract;

class AzureOpenAIClient
{
    private const string API_VERSION = '2024-10-21';

    public function client(?string $kind = null): ClientContract
    {
        $endpoint = rtrim((string) config('azure_openai.uri'), '/');
        $deployment = $this->deploymentFor($kind);
        $timeout = (int) config('azure_openai.timeout');

        $factory = OpenAI::factory()
            ->withHttpHeader('api-key', (string) config('azure_openai.api_key'))
            ->withHttpHeader('User-Agent', 'TemanLari-Temari/1.0')
            ->withQueryParam('api-version', self::API_VERSION);

        if ($endpoint !== '' && $deployment !== '') {
            $factory = $factory->withBaseUri("{$endpoint}/openai/deployments/{$deployment}");
        }
        if ($timeout > 0) {
            $factory = $factory->withHttpClient(new Client(['timeout' => $timeout]));
        }

        return $factory->make();
    }

    /**
     * The Azure deployment (model) name for $kind: the per-narrator override,
     * which already falls back to the general AZURE_OPENAI_DEPLOYMENT in config.
     * A null/unmapped kind uses the general deployment directly.
     */
    public function deploymentFor(?string $kind): string
    {
        $perKind = $kind === null ? null : config("azure_openai.narrators.{$kind}");

        return (string) ($perKind ?: config('azure_openai.deployment'));
    }
}
