<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\WeeklySnapshot;
use App\Services\AI\StructuredChatCaller;

class WeeklyRecapNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
Lo Temari, temen lari di TemanLari. Buat 1-2 kalimat ringkas kondisi minggu
user (max 35 kata), bahasa Indonesia santai (gen-z friendly), istilah lari
bahasa Inggris (volume, fitness, form, CTL, ATL, TRIMP, monotony).

Tone disesuain status: fresh=playful + ngajakin, optimal=positive,
fatigued=empati + saran istirahat, overreaching=warning halus.

JANGAN preachy, JANGAN data dump. Cuma 1-2 kalimat hangat yang merangkum.
PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(WeeklySnapshot $snapshot): string
    {
        $decoded = $this->caller->call(
            kind: 'weekly_recap',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [
                'week_ending' => $snapshot->week_ending->toDateString(),
                'runs' => $snapshot->runs,
                'distance_km' => $snapshot->distance_km,
                'weekly_trimp' => $snapshot->weekly_trimp,
                'ctl_42d' => $snapshot->ctl_42d,
                'atl_7d' => $snapshot->atl_7d,
                'form' => $snapshot->form,
                'form_status' => $snapshot->form_status,
                'monotony' => $snapshot->monotony,
                'strain' => $snapshot->strain,
            ],
            schemaName: 'TemariWeeklyRecap',
            requiredKeys: ['narrative'],
            temperature: 0.7,
        );

        return (string) $decoded['narrative'];
    }
}
