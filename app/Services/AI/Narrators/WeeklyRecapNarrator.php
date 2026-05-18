<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Exceptions\AI\UnavailableException;
use App\Models\WeeklySnapshot;
use App\Services\AI\AzureOpenAIClient;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

use function is_array;

class WeeklyRecapNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
Lo Temari, temen lari di TemanLari. Buat 1-2 kalimat ringkas kondisi minggu
user (max 35 kata), bahasa Indonesia santai (gen-z friendly), istilah lari
bahasa Inggris (volume, fitness, form, CTL, ATL, TRIMP, monotony).

Tone disesuain status: fresh=playful + ngajakin, optimal=positive,
fatigued=empati + saran istirahat, overreaching=warning halus.

JANGAN preachy, JANGAN data dump. Cuma 1-2 kalimat hangat yang merangkum.
PROMPT;

    public function __construct(private readonly AzureOpenAIClient $azure)
    {
    }

    public function generate(WeeklySnapshot $snapshot): string
    {
        return $this->call([
            'week_ending' => $snapshot->week_ending->toDateString(),
            'runs' => $snapshot->runs,
            'distance_km' => $snapshot->distance_km,
            'weekly_trimp' => $snapshot->weekly_trimp,
            'ctl_42d' => $snapshot->ctl_42d,
            'atl_7d' => $snapshot->atl_7d,
            'form' => $snapshot->form,
            'form_status' => $snapshot->form_status,
            'monotony' => $snapshot->monotony,
            'strain' => $snapshot->strain,
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
                'kind' => 'weekly_recap',
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

        if (! is_array($decoded) || ! isset($decoded['narrative']) || ! is_string($decoded['narrative'])) {
            throw new UnavailableException('Azure OpenAI structured output missing narrative');
        }

        return $decoded['narrative'];
    }

    /** @return array{type: string, json_schema: array<string, mixed>} */
    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'TemariWeeklyRecap',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'narrative' => ['type' => 'string'],
                    ],
                    'required' => ['narrative'],
                ],
            ],
        ];
    }
}
