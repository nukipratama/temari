<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\MetricsContext;
use App\Services\Run\Story\Vibe;
use Illuminate\Support\Carbon;

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
        private readonly StructuredChatCaller $caller,
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

        $decoded = $this->caller->call(
            kind: 'briefing',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->buildContext($ctx),
            schemaName: 'TemariBriefing',
            requiredKeys: ['headline', 'suggestion'],
        );

        return [
            'headline' => (string) $decoded['headline'],
            'suggestion' => (string) $decoded['suggestion'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(MetricsContext $ctx): array
    {
        $verdictSummary = array_map(
            fn ($v): array => ['mood' => $v->mood, 'km' => $v->distanceKm, 'oneline' => $v->oneline],
            array_slice($ctx->recentVerdicts, 0, 5),
        );

        return [
            'name' => $ctx->user->firstName(),
            'vibe' => $ctx->vibeState,
            'load' => $ctx->load,
            'recent_runs' => $verdictSummary,
            'date' => $ctx->asOf->toDateString(),
        ];
    }
}
