<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Exceptions\AI\UnavailableException;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AzureOpenAIClient;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

use function is_array;

class TrendCaptionNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
Lo Temari, temen lari di TemanLari. Buat 1 kalimat caption max 25 kata untuk
chart Fitness/Form + Weekly Volume user. Pakai bahasa Indonesia santai (gen-z
friendly), istilah lari/load bahasa Inggris (CTL, ATL, form, fitness, volume).

Fokus ke tren (naik/turun, plateau, peak). Sebut konteks kalau ada (PR week,
recovery week, taper).

JANGAN preachy, JANGAN data dump.
PROMPT;

    public function __construct(
        private readonly AzureOpenAIClient $azure,
        private readonly TrainingLoad $trainingLoad,
    ) {
    }

    public function generate(User $user, Carbon $asOf): string
    {
        $weeks = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->orderByDesc('week_ending')
            ->limit(12)
            ->get()
            ->reverse()
            ->values();

        return $this->call([
            'as_of' => $asOf->toDateString(),
            'load_today' => $this->trainingLoad->summary($user, $asOf),
            'weeks' => $weeks->map(fn (WeeklySnapshot $w): array => [
                'ending' => $w->week_ending->toDateString(),
                'distance_km' => $w->distance_km,
                'trimp' => $w->weekly_trimp,
                'ctl_42d' => $w->ctl_42d,
                'atl_7d' => $w->atl_7d,
                'form' => $w->form,
                'status' => $w->form_status,
            ])->all(),
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
                'kind' => 'trend_caption',
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

        if (! is_array($decoded) || ! isset($decoded['caption']) || ! is_string($decoded['caption'])) {
            throw new UnavailableException('Azure OpenAI structured output missing caption');
        }

        return $decoded['caption'];
    }

    /** @return array{type: string, json_schema: array<string, mixed>} */
    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'TemariTrendCaption',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'caption' => ['type' => 'string'],
                    ],
                    'required' => ['caption'],
                ],
            ],
        ];
    }
}
