<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\AI\AzureOpenAIClient;
use App\Services\Run\Metrics\StreamSummary;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

use function is_array;

/**
 * One LLM call generates three short interpretations of an activity. Callers
 * cache the result for ~5 minutes so three parallel jobs (one per analysis
 * type) only pay for the Azure round-trip once.
 */
class RunInsightNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
Lo Temari, temen lari di TemanLari. Buat 3 catatan interpretasi sesi lari user,
masing-masing 1-2 kalimat max 30 kata, bahasa Indonesia santai (gen-z friendly).
Istilah lari tetep bahasa Inggris (pace, splits, cadence, decoupling, HR zone,
Z1-Z5, easy, tempo, long run, negative split).

- technical: terjemahin cadence + decoupling + HR ke bahasa awam ("cadence 172
  udah ideal", "decoupling +12% sinyal HR drift, base belum solid").
- splits: highlight 1-2 km paling menarik dari splits ("split 4 di 6:09 — tercepat
  hari ini, push di tanjakan") atau pacing pattern.
- zones: 1 kalimat interpretasi HR zone breakdown ("70% di Z2, base building proper").

JANGAN preachy, JANGAN data dump tanpa konteks. Jangan judging.
PROMPT;

    public function __construct(
        private readonly AzureOpenAIClient $azure,
    ) {
    }

    /**
     * @return array{technical: string, splits: string, zones: string}
     */
    public function generate(Activity $activity, ActivityDetail $detail): array
    {
        return $this->call($this->buildContext($detail));
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array{technical: string, splits: string, zones: string}
     */
    private function call(array $ctx): array
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
                'kind' => 'run_insight',
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

        if (! is_array($decoded)
            || ! isset($decoded['technical'], $decoded['splits'], $decoded['zones'])
            || ! is_string($decoded['technical'])
            || ! is_string($decoded['splits'])
            || ! is_string($decoded['zones'])
        ) {
            throw new UnavailableException('Azure OpenAI structured output missing required fields');
        }

        Log::info('narrator.ai.call', [
            'kind' => 'run_insight',
            'status' => 'ok',
            'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'usage' => [
                'prompt' => $response->usage->promptTokens ?? null,
                'completion' => $response->usage->completionTokens ?? null,
                'total' => $response->usage->totalTokens ?? null,
            ],
        ]);

        return [
            'technical' => $decoded['technical'],
            'splits' => $decoded['splits'],
            'zones' => $decoded['zones'],
        ];
    }

    /** @return array<string, mixed> */
    private function buildContext(ActivityDetail $detail): array
    {
        $summary = is_array($detail->stream_summary) ? $detail->stream_summary : [];
        $zonePct = StreamSummary::zonePct($summary);

        return [
            'distance_km' => round(((float) ($detail->distance ?? 0)) / 1000, 2),
            'moving_time_sec' => $detail->moving_time,
            'avg_hr' => $detail->average_heartrate,
            'max_hr' => $detail->max_heartrate,
            'avg_cadence_spm' => $detail->average_cadence !== null
                ? (int) round((float) $detail->average_cadence * 2)
                : null,
            'decoupling_pct' => $summary['decoupling_pct'] ?? null,
            'negative_split' => $summary['negative_split'] ?? null,
            'zone_pct' => $zonePct,
            'per_km' => $summary['per_km'] ?? null,
            'ascent_m' => $summary['ascent_m'] ?? null,
            'weather_temp_c' => $detail->weather_temp_c,
            'weather_humidity_pct' => $detail->weather_humidity_pct,
        ];
    }

    /** @return array{type: string, json_schema: array<string, mixed>} */
    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'TemariRunInsight',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'technical' => ['type' => 'string'],
                        'splits' => ['type' => 'string'],
                        'zones' => ['type' => 'string'],
                    ],
                    'required' => ['technical', 'splits', 'zones'],
                ],
            ],
        ];
    }
}
