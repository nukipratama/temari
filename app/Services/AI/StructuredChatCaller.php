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
    public function __construct(
        private AzureOpenAIClient $azure,
        private TokenUsageRecorder $usageRecorder,
    ) {
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
        ?ChatCallOptions $options = null,
    ): array {
        $options ??= new ChatCallOptions();
        $startedAt = microtime(true);
        $effectiveMaxTokens = $options->maxTokens ?? (int) config('azure_openai.max_completion_tokens');

        try {
            $response = $this->azure->client()->chat()->create([
                'model' => (string) config('azure_openai.deployment'),
                'messages' => [
                    ['role' => 'system', 'content' => TemariPersona::systemPrompt()."\n\n".$systemPrompt],
                    ['role' => 'user', 'content' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                ],
                'max_completion_tokens' => $effectiveMaxTokens,
                'temperature' => $options->temperature,
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

        $promptTokens = (int) ($response->usage->promptTokens ?? 0);
        $completionTokens = (int) ($response->usage->completionTokens ?? 0);
        $totalTokens = (int) ($response->usage->totalTokens ?? 0);
        $finishReason = (string) ($response->choices[0]->finishReason ?? '');
        $truncated = $finishReason === 'length';
        $latencyMs = self::latencyMs($startedAt);

        if ($truncated) {
            Log::warning('narrator.ai.truncated', [
                'kind' => $kind,
                'completion_tokens' => $completionTokens,
                'max_completion_tokens' => $effectiveMaxTokens,
            ]);
        }

        Log::info('narrator.ai.call', [
            'kind' => $kind,
            'status' => 'ok',
            'latency_ms' => $latencyMs,
            'truncated' => $truncated,
            'usage' => [
                'prompt' => $promptTokens,
                'completion' => $completionTokens,
                'total' => $totalTokens,
            ],
        ]);

        $this->usageRecorder->record(
            $kind,
            $promptTokens,
            $completionTokens,
            $totalTokens,
            (string) config('azure_openai.deployment') ?: null,
            $latencyMs,
            $truncated,
            $options->userId,
        );

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
