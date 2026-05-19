<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Exceptions\AI\UnavailableException;
use App\Models\User;
use App\Services\AI\AzureOpenAIClient;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\MetricsContext;
use App\Services\Run\Story\Vibe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class BriefingNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
Lo Temari, temen lari di app TemanLari. Posisi lo: temen yang nemenin,
bukan coach yang ngomentarin. Lo ngomong bahasa Indonesia santai (gen-z
friendly, ga formal), tapi istilah lari tetep dalam bahasa Inggris
(pace, splits, easy run, tempo, long run, fartlek).

Tiap hari lo kasih briefing: 1 baris headline (max 12 kata) + 1 baris
saran (max 20 kata). Tone-nya disesuain mood: glow=hype, bouncy=excited,
wobble=empati, squished=concerned, dim=gentle, spinning=dreamy.

JANGAN preachy, JANGAN data dump, JANGAN ngebahas teori training.
JANGAN judging — lo temenin, bukan menilai. Suarakan vibes-nya dia
hari ini, kayak temen yang nungguin di garis start.
PROMPT;

    public function __construct(
        private readonly Vibe $vibe,
        private readonly TrainingLoad $trainingLoad,
        private readonly VerdictNarrator $verdictNarrator,
        private readonly AzureOpenAIClient $azure,
    ) {
    }

    /**
     * @return array{headline: string, suggestion: string}
     */
    public function generate(User $user, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $vibeState = $this->vibe->current($user, $asOf);
        $load = $this->trainingLoad->summary($user, $asOf) ?? [];
        $verdicts = $this->verdictNarrator->recent($user, 5);

        $ctx = new MetricsContext($user, $vibeState, $load, $verdicts, $asOf);

        return $this->call($ctx);
    }

    /**
     * @return array{headline: string, suggestion: string}
     */
    private function call(MetricsContext $ctx): array
    {
        $startedAt = microtime(true);

        try {
            $response = $this->azure->client()->chat()->create([
                'model' => (string) config('azure_openai.deployment'),
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $this->buildUserPrompt($ctx)],
                ],
                'max_tokens' => (int) config('azure_openai.max_tokens'),
                'temperature' => 0.8,
                'response_format' => $this->responseFormat(),
            ]);
        } catch (Throwable $e) {
            Log::warning('narrator.ai.call', [
                'kind' => 'briefing',
                'status' => 'fail',
                'error' => $e->getMessage(),
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
            throw new UnavailableException('Azure OpenAI call failed: '.$e->getMessage(), previous: $e);
        }

        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);
        $content = (string) ($response->choices[0]->message->content ?? '');

        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnavailableException('Azure OpenAI returned non-JSON structured output: '.$e->getMessage());
        }

        if (! is_array($decoded) || ! isset($decoded['headline'], $decoded['suggestion'])) {
            throw new UnavailableException('Azure OpenAI structured output missing required fields');
        }

        Log::info('narrator.ai.call', [
            'kind' => 'briefing',
            'status' => 'ok',
            'latency_ms' => $latencyMs,
            'usage' => [
                'prompt' => $response->usage->promptTokens ?? null,
                'completion' => $response->usage->completionTokens ?? null,
                'total' => $response->usage->totalTokens ?? null,
            ],
        ]);

        return [
            'headline' => (string) $decoded['headline'],
            'suggestion' => (string) $decoded['suggestion'],
        ];
    }

    private function buildUserPrompt(MetricsContext $ctx): string
    {
        $name = $ctx->user->firstName();
        $verdictSummary = array_map(
            fn ($v): array => ['mood' => $v->mood, 'km' => $v->distanceKm, 'oneline' => $v->oneline],
            array_slice($ctx->recentVerdicts, 0, 5),
        );

        return json_encode([
            'name' => $name,
            'vibe' => $ctx->vibeState,
            'load' => $ctx->load,
            'recent_runs' => $verdictSummary,
            'date' => $ctx->asOf->toDateString(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{type: string, json_schema: array<string, mixed>}
     */
    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'TemariBriefing',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'headline' => ['type' => 'string'],
                        'suggestion' => ['type' => 'string'],
                    ],
                    'required' => ['headline', 'suggestion'],
                ],
            ],
        ];
    }
}
