<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AI\UnavailableException;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Thin shared wrapper around the Azure OpenAI chat completions call. Handles
 * the request, structured-JSON decoding, logging, and exception mapping that
 * every narrator otherwise duplicates.
 */
final readonly class StructuredChatCaller
{
    private const string GLOBAL_STYLE_RULES = <<<'RULES'

Aturan gaya tulisan (WAJIB):
- JANGAN PERNAH pakai em dash (—) atau en dash (–) di output. Kalau mau jeda, pakai koma, titik, atau kata sambung biasa.
RULES;

    public function __construct(private AzureOpenAIClient $azure)
    {
    }

    /**
     * @param  list<string>  $requiredKeys
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function call(
        string $kind,
        string $systemPrompt,
        array $context,
        string $schemaName,
        array $requiredKeys,
        float $temperature = 0.8,
    ): array {
        $startedAt = microtime(true);

        try {
            $response = $this->azure->client()->chat()->create([
                'model' => (string) config('azure_openai.deployment'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt.self::GLOBAL_STYLE_RULES],
                    ['role' => 'user', 'content' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                ],
                'max_completion_tokens' => (int) config('azure_openai.max_completion_tokens'),
                'temperature' => $temperature,
                'response_format' => self::responseFormat($schemaName, $requiredKeys),
            ]);
        } catch (Throwable $e) {
            Log::warning('narrator.ai.call', [
                'kind' => $kind,
                'status' => 'fail',
                'error' => $e->getMessage(),
                'latency_ms' => self::latencyMs($startedAt),
            ]);
            throw new UnavailableException('Azure OpenAI call failed: '.$e->getMessage(), previous: $e);
        }

        $content = (string) ($response->choices[0]->message->content ?? '');

        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnavailableException('Azure OpenAI returned non-JSON: '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new UnavailableException('Azure OpenAI structured output not an object');
        }

        $missingLabel = $this->missingKeyLabel($decoded, $requiredKeys);
        if ($missingLabel !== null) {
            throw new UnavailableException("Azure OpenAI structured output missing {$missingLabel}");
        }

        Log::info('narrator.ai.call', [
            'kind' => $kind,
            'status' => 'ok',
            'latency_ms' => self::latencyMs($startedAt),
            'usage' => [
                'prompt' => $response->usage->promptTokens ?? null,
                'completion' => $response->usage->completionTokens ?? null,
                'total' => $response->usage->totalTokens ?? null,
            ],
        ]);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  list<string>  $requiredKeys
     */
    private function missingKeyLabel(array $decoded, array $requiredKeys): ?string
    {
        $missing = [];
        foreach ($requiredKeys as $key) {
            if (! isset($decoded[$key]) || ! is_string($decoded[$key])) {
                $missing[] = $key;
            }
        }

        if ($missing === []) {
            return null;
        }

        return count($requiredKeys) === 1 ? $missing[0] : 'required fields';
    }

    /**
     * @param  list<string>  $requiredKeys
     * @return array{type: string, json_schema: array<string, mixed>}
     */
    private static function responseFormat(string $schemaName, array $requiredKeys): array
    {
        $properties = [];
        foreach ($requiredKeys as $key) {
            $properties[$key] = ['type' => 'string'];
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $schemaName,
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => $properties,
                    'required' => $requiredKeys,
                ],
            ],
        ];
    }

    private static function latencyMs(float $startedAt): int
    {
        return (int) ((microtime(true) - $startedAt) * 1000);
    }
}
