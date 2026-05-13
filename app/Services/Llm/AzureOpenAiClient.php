<?php

declare(strict_types=1);

namespace App\Services\Llm;

use GuzzleHttp\Client;
use OpenAI;
use OpenAI\Contracts\ClientContract;

/**
 * Thin wrapper around openai-php that targets Azure OpenAI specifically:
 * Azure uses `api-key` header (not Bearer), a baseUri that bakes in the
 * deployment path, and a required `api-version` query param.
 *
 * Resolves a fresh client per request because Azure config can change at
 * runtime (e.g. tests overriding env). Cheap to construct — Guzzle PSR
 * client is lazy.
 */
final class AzureOpenAiClient
{
    private const string DEFAULT_API_VERSION = '2024-10-21';

    public function client(): ClientContract
    {
        $uri = (string) config('azure_openai.uri');
        $apiKey = (string) config('azure_openai.api_key');
        $timeout = (int) config('azure_openai.timeout');

        [$baseUri, $apiVersion] = $this->splitUri($uri);

        $factory = OpenAI::factory()
            ->withHttpHeader('api-key', $apiKey)
            ->withHttpHeader('User-Agent', 'TemanLari-Temari/1.0')
            ->withQueryParam('api-version', $apiVersion);

        if ($baseUri !== '') {
            $factory = $factory->withBaseUri($baseUri);
        }
        if ($timeout > 0) {
            $factory = $factory->withHttpClient(new Client(['timeout' => $timeout]));
        }

        return $factory->make();
    }

    /**
     * Split AZURE_OPENAI_URI into (base, api-version). Accepts the full URL
     * up to /chat/completions (which openai-php appends itself).
     *
     *   in : https://x.openai.azure.com/openai/deployments/y/chat/completions?api-version=2024-10-21
     *   out: ['https://x.openai.azure.com/openai/deployments/y', self::DEFAULT_API_VERSION]
     *
     * @return array{0: string, 1: string}
     */
    private function splitUri(string $uri): array
    {
        if ($uri === '') {
            return ['', self::DEFAULT_API_VERSION];
        }

        $parts = parse_url($uri);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = (string) ($parts['path'] ?? '');
        $path = preg_replace('#/(chat/completions|responses)/?$#', '', $path) ?? $path;

        parse_str($parts['query'] ?? '', $queryParts);
        $rawVersion = $queryParts['api-version'] ?? self::DEFAULT_API_VERSION;
        $apiVersion = \is_string($rawVersion) ? $rawVersion : self::DEFAULT_API_VERSION;

        return [
            $scheme.'://'.$host.$path,
            $apiVersion,
        ];
    }
}
