<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\MetricsContext;
use App\Services\Run\Story\Vibe;
use Illuminate\Support\Carbon;

class BriefingNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: kasih briefing harian. Output dua bagian:
        - headline: 1 baris, max 12 kata.
        - suggestion: 1 baris, max 20 kata.

        Tone-nya disesuain mood pengguna hari ini (lihat field `vibe`). Untuk mood
        spesifik briefing: glow=hype banget, bouncy=excited+ngajakin, wobble=empati,
        squished=concerned, dim=gentle, spinning=dreamy.

        Pakai field `context` buat personalisasi:
        - `this_week_runs` / `last_week_runs` → kalau minggu ini > minggu lalu,
          apresiasi; kalau < minggu lalu, dorong satu lari kecil.
        - `recovery_hours` → kalau < 24 jam dari lari terakhir, suggest easy/rest;
          kalau > 48 jam, oke buat sesi quality lagi.
        - `time_bucket` → "pagi"/"subuh" frame ajakan lari pagi; "sore" frame
          lari sore atau cooldown; "malam" frame istirahat / nonton replay hari ini.
        - `consecutive_weeks_active` → kalau 3+, kasih kredit konsistensi.

        Suarakan vibes hari ini, kayak temen yang nungguin di garis start. JANGAN
        ngebahas teori training. Cuma vibes + saran ringan.
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
            options: new ChatCallOptions(userId: $user->id, maxTokens: 800),
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
            'context' => BriefingContext::forUser($ctx->user, $ctx->asOf)->toArray(),
        ];
    }
}
