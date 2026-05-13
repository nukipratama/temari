<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Narrators;

use Throwable;
use App\Models\User;
use App\Services\Llm\AzureOpenAiClient;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingResult;
use App\Services\Run\Story\Contracts\BriefingNarrator;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\FormStatus;
use App\Services\Run\Story\MetricsContext;
use App\Services\Run\Story\Temari;
use App\Services\Run\Story\Vibe;
use App\Services\Llm\LlmNarratorException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * LLM-backed briefing narrator. Talks to Azure OpenAI's Chat Completions
 * API via openai-php/laravel client (Azure baseUri + api-key header
 * configured in App\Services\Llm\AzureOpenAiClient).
 *
 * Sigil pattern + accessory are intentionally NOT LLM-generated — those
 * come from the existing rule-based moodFor* logic on the Briefing
 * service so the visual mascot stays stable per mood across runs.
 */
class LlmBriefingNarrator implements BriefingNarrator
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
        private readonly AzureOpenAiClient $azure,
    ) {
    }

    public function generate(User $user, ?Carbon $asOf = null): BriefingResult
    {
        $asOf ??= Carbon::today();
        $vibeState = $this->vibe->current($user, $asOf);
        $load = $this->trainingLoad->summary($user, $asOf) ?? [];
        $verdicts = $this->verdictNarrator->recent($user, 5);

        $ctx = new MetricsContext($user, $vibeState, $load, $verdicts, $asOf);
        $structured = $this->call($ctx);

        return $this->mapToResult($structured, $ctx);
    }

    /**
     * @return array{mood: string, headline: string, suggestion: string, vibe_label: string, vibe_emoji: string}
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
            Log::warning('narrator.llm.call', [
                'kind' => 'briefing',
                'status' => 'fail',
                'error' => $e->getMessage(),
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
            throw new LlmNarratorException('Azure OpenAI call failed: '.$e->getMessage(), previous: $e);
        }

        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);
        $content = (string) ($response->choices[0]->message->content ?? '');

        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LlmNarratorException('Azure OpenAI returned non-JSON structured output: '.$e->getMessage());
        }

        if (! \is_array($decoded) || ! isset($decoded['mood'], $decoded['headline'], $decoded['suggestion'], $decoded['vibe_label'], $decoded['vibe_emoji'])) {
            throw new LlmNarratorException('Azure OpenAI structured output missing required fields');
        }

        Log::info('narrator.llm.call', [
            'kind' => 'briefing',
            'status' => 'ok',
            'latency_ms' => $latencyMs,
            'usage' => [
                'prompt' => $response->usage->promptTokens ?? null,
                'completion' => $response->usage->completionTokens ?? null,
                'total' => $response->usage->totalTokens ?? null,
            ],
        ]);

        return $decoded;
    }

    private function buildUserPrompt(MetricsContext $ctx): string
    {
        $name = $ctx->user->firstName();
        $verdictSummary = \array_map(
            fn ($v): array => ['mood' => $v->mood, 'km' => $v->distanceKm, 'oneline' => $v->oneline],
            \array_slice($ctx->recentVerdicts, 0, 5),
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
                        'mood' => ['type' => 'string', 'enum' => [
                            Temari::MOOD_GLOW,
                            Temari::MOOD_BOUNCY,
                            Temari::MOOD_WOBBLE,
                            Temari::MOOD_SQUISHED,
                            Temari::MOOD_DIM,
                            Temari::MOOD_SPINNING,
                        ]],
                        'headline' => ['type' => 'string'],
                        'suggestion' => ['type' => 'string'],
                        'vibe_label' => ['type' => 'string'],
                        'vibe_emoji' => ['type' => 'string'],
                    ],
                    'required' => ['mood', 'headline', 'suggestion', 'vibe_label', 'vibe_emoji'],
                ],
            ],
        ];
    }

    /**
     * @param  array{mood: string, headline: string, suggestion: string, vibe_label: string, vibe_emoji: string}  $structured
     */
    private function mapToResult(array $structured, MetricsContext $ctx): BriefingResult
    {
        $mood = $structured['mood'];

        return new BriefingResult(
            vibeState: $ctx->vibeState,
            vibeLabel: $structured['vibe_label'],
            vibeEmoji: $structured['vibe_emoji'],
            headlineLine: $structured['headline'],
            suggestionLine: $structured['suggestion'],
            recoveryLabel: FormStatus::label($ctx->load),
            recoveryTone: FormStatus::tone($ctx->load),
            streakLabel: null,
            sigilPattern: Temari::sigilForMoodPublic($mood),
            accessory: Temari::accessoryForMoodPublic($mood),
            mood: $mood,
            degraded: false,
        );
    }

}
