<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\StreamSummary;

use function is_array;
use function is_string;

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
        );

        return (string) $decoded['speech'];
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
}
