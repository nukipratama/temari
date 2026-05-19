<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\StreamSummary;

use function is_array;

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

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    /**
     * @return array{technical: string, splits: string, zones: string}
     */
    public function generate(Activity $activity, ActivityDetail $detail): array
    {
        $decoded = $this->caller->call(
            kind: 'run_insight',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->buildContext($detail),
            schemaName: 'TemariRunInsight',
            requiredKeys: ['technical', 'splits', 'zones'],
            temperature: 0.7,
        );

        return [
            'technical' => (string) $decoded['technical'],
            'splits' => (string) $decoded['splits'],
            'zones' => (string) $decoded['zones'],
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
}
