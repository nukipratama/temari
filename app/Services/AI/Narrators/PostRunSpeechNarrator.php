<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Services\AI\AzureOpenAIClient;
use App\Services\Run\Metrics\StreamSummary;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

use function is_array;

class PostRunSpeechNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
Lo Temari, temen lari di TemanLari. Buat 1 kalimat post-run buat user
abis kelar lari, max 24 kata. Bahasa Indonesia santai (gen-z friendly),
tapi istilah lari tetep bahasa Inggris (pace, splits, easy, tempo, long
run, negative split, decoupling, cardiac drift).

Tone disesuain mood: glow=bangga (PR/highlight), bouncy=excited (negative
split / strong finish), wobble=empati (cardiac drift / HR drift), spinning=
catatan capek (sesi keras), squished=acknowledge cuaca panas, dim=netral/
konsisten.

JANGAN preachy, JANGAN data dump, JANGAN ngoreksi. Cuma 1 kalimat
hangat yang nyambungin angka ke perasaan.
PROMPT;

    public function __construct(
        private readonly AzureOpenAIClient $azure,
    ) {
    }

    public function generate(Activity $activity, ActivityDetail $detail, string $mood): string
    {
        $hasPr = PersonalRecord::query()->where('activity_id', $activity->id)->exists();
        $ctx = $this->buildContext($detail, $mood, $hasPr);

        return $this->call($ctx);
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
                'temperature' => 0.8,
                'response_format' => $this->responseFormat(),
            ]);
        } catch (Throwable $e) {
            Log::warning('narrator.ai.call', [
                'kind' => 'post_run_speech',
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

        if (! is_array($decoded) || ! isset($decoded['speech']) || ! is_string($decoded['speech'])) {
            throw new UnavailableException('Azure OpenAI structured output missing speech');
        }

        Log::info('narrator.ai.call', [
            'kind' => 'post_run_speech',
            'status' => 'ok',
            'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'usage' => [
                'prompt' => $response->usage->promptTokens ?? null,
                'completion' => $response->usage->completionTokens ?? null,
                'total' => $response->usage->totalTokens ?? null,
            ],
        ]);

        return $decoded['speech'];
    }

    /** @return array<string, mixed> */
    private function buildContext(ActivityDetail $detail, string $mood, bool $hasPr): array
    {
        $summary = is_array($detail->stream_summary) ? $detail->stream_summary : [];
        $zonePct = StreamSummary::zonePct($summary);
        $dominantZone = $zonePct === []
            ? null
            : array_search(max($zonePct), $zonePct, strict: true);

        return [
            'mood' => $mood,
            'has_pr' => $hasPr,
            'distance_km' => round(((float) ($detail->distance ?? 0)) / 1000, 1),
            'dominant_zone' => is_string($dominantZone) ? $dominantZone : null,
            'decoupling_pct' => $summary['decoupling_pct'] ?? null,
            'negative_split' => $summary['negative_split'] ?? null,
            'weather_temp_c' => $detail->weather_temp_c,
            'weather_rain' => $detail->weather_rain_detected,
        ];
    }

    /** @return array{type: string, json_schema: array<string, mixed>} */
    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'TemariPostRunSpeech',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'speech' => ['type' => 'string'],
                    ],
                    'required' => ['speech'],
                ],
            ],
        ];
    }
}
