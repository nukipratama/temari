<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Exceptions\AI\UnavailableException;
use App\Models\PersonalRecord;
use App\Services\AI\AzureOpenAIClient;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

use function is_array;

class PrContextNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
Lo Temari, temen lari di TemanLari. Buat 1 kalimat flavor untuk Personal Record
user (max 22 kata), bahasa Indonesia santai (gen-z friendly), istilah lari bahasa
Inggris.

Highlight kalau ada delta dari PR sebelumnya. Kalau ini PR pertama di kategori,
bilang "PR pertama!" atau yang setara. Tone selalu bangga + supportive.

JANGAN preachy, JANGAN data dump.
PROMPT;

    public function __construct(private readonly AzureOpenAIClient $azure)
    {
    }

    public function generate(PersonalRecord $pr): string
    {
        $previous = PersonalRecord::query()
            ->where('user_id', $pr->user_id)
            ->where('category', $pr->category)
            ->where('id', '<>', $pr->id)
            ->orderByDesc('set_at')
            ->first();

        return $this->call([
            'category' => $pr->category,
            'value_sec' => $pr->value_sec,
            'set_at' => $pr->set_at->toDateString(),
            'previous_value_sec' => $previous?->value_sec,
            'previous_set_at' => $previous?->set_at?->toDateString(),
            'delta_sec' => $previous !== null ? ($previous->value_sec - $pr->value_sec) : null,
        ]);
    }

    /** @param  array<string, mixed>  $ctx */
    private function call(array $ctx): string
    {
        $startedAt = microtime(true);

        try {
            $response = $this->azure->client()->chat()->create([
                'model' => (string) config('azure_openai.deployment'),
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => json_encode($ctx, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                ],
                'max_tokens' => (int) config('azure_openai.max_tokens'),
                'temperature' => 0.7,
                'response_format' => $this->responseFormat(),
            ]);
        } catch (Throwable $e) {
            Log::warning('narrator.ai.call', [
                'kind' => 'pr_context',
                'status' => 'fail',
                'error' => $e->getMessage(),
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
            throw new UnavailableException('Azure OpenAI call failed: '.$e->getMessage(), previous: $e);
        }

        $content = (string) ($response->choices[0]->message->content ?? '');

        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnavailableException('Azure OpenAI returned non-JSON: '.$e->getMessage());
        }

        if (! is_array($decoded) || ! isset($decoded['flavor']) || ! is_string($decoded['flavor'])) {
            throw new UnavailableException('Azure OpenAI structured output missing flavor');
        }

        return $decoded['flavor'];
    }

    /** @return array{type: string, json_schema: array<string, mixed>} */
    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'TemariPrContext',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'flavor' => ['type' => 'string'],
                    ],
                    'required' => ['flavor'],
                ],
            ],
        ];
    }
}
