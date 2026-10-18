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
        $endpoint = rtrim(config()->string('azure_openai.uri'), '/');
        $timeout = config()->integer('azure_openai.timeout');

        $factory = OpenAI::factory()
            ->withHttpHeader('api-key', config()->string('azure_openai.api_key'))
            // Azure's OpenAI-compatible v1 GA surface needs no api-version; the
            // deployment is selected by the request body's `model` field.
            ->withHttpHeader('User-Agent', 'Temari/1.0');

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
        assert($perKind === null || is_string($perKind));

        return $perKind ?: config()->string('azure_openai.deployment');
    }
}
