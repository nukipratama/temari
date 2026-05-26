<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\StreamSummary;

use function is_string;

class PostRunSpeechNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 1 kalimat post-run untuk pengguna setelah selesai lari, maksimal 24
        kata.

        Sesuaikan tone dengan mood: glow=bangga (PR atau highlight); bouncy=excited
        (negative split atau strong finish); wobble=empatik (cardiac drift atau HR
        drift); spinning=catatan kelelahan (sesi keras); squished=acknowledge cuaca
        panas; dim=netral atau konsisten.

        Cukup 1 kalimat hangat yang menghubungkan angka ke perasaan. Jangan
        mengoreksi atau menggurui.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(Activity $activity, ActivityDetail $detail, string $mood): string
    {
        $hasPr = PersonalRecord::query()->where('activity_id', $activity->id)->exists();

        $decoded = $this->caller->call(
            kind: 'post_run_speech',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->buildContext($detail, $mood, $hasPr),
            schemaName: 'TemariPostRunSpeech',
            requiredKeys: ['speech'],
            options: new ChatCallOptions(userId: $activity->user_id, maxTokens: 1024),
        );

        return (string) $decoded['speech'];
    }

    /** @return array<string, mixed> */
    private function buildContext(ActivityDetail $detail, string $mood, bool $hasPr): array
    {
        $summary = $detail->streamSummary();
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
}
