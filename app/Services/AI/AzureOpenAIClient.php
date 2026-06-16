<?php

declare(strict_types=1);

namespace App\Services\AI;

use GuzzleHttp\Client;
use OpenAI;
use OpenAI\Contracts\ClientContract;

class AzureOpenAIClient
{
    private const string DEFAULT_API_VERSION = '2024-10-21';

    public function client(?string $kind = null): ClientContract
    {
        $profile = $this->resolveProfile($kind);
        $uri = $profile['uri'];
        $apiKey = $profile['api_key'];
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
     * Resolve the deployment slug for $kind, falling back to the default profile.
     */
    public function deploymentFor(?string $kind): string
    {
        return $this->resolveProfile($kind)['deployment'];
    }

    /**
     * Resolve the routing profile for $kind. A per-kind profile is used only when
     * BOTH its uri and api_key are non-empty; otherwise the default/primary
     * profile is returned.
     *
     * @return array{uri: string, api_key: string, deployment: string}
     */
    private function resolveProfile(?string $kind): array
    {
        $default = [
            'uri' => (string) config('azure_openai.uri'),
            'api_key' => (string) config('azure_openai.api_key'),
            'deployment' => (string) config('azure_openai.deployment'),
        ];

        if ($kind === null) {
            return $default;
        }

        /** @var array{uri?: string, api_key?: string, deployment?: string}|null $profile */
        $profile = config("azure_openai.narrators.{$kind}");

        if ($profile === null) {
            return $default;
        }

        $uri = (string) ($profile['uri'] ?? '');
        $apiKey = (string) ($profile['api_key'] ?? '');

        if ($uri === '' || $apiKey === '') {
            return $default;
        }

        return [
            'uri' => $uri,
            'api_key' => $apiKey,
            'deployment' => (string) ($profile['deployment'] ?? ''),
        ];
    }

    /**
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
