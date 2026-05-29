<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\WeeklySnapshot;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\PaceCalculator;

class WeeklyRecapNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 1-2 kalimat ringkas kondisi minggu pengguna, maksimal 35 kata.

        Sesuaikan tone dengan status: fresh=energik dan mengajak; optimal=positif;
        fatigued=empatik dengan saran istirahat; overreaching=warning halus.

        Rangkum vibe minggu ini, tidak perlu menjelaskan setiap angka.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(WeeklySnapshot $snapshot): string
    {
        $paceSecPerKm = PaceCalculator::secPerKm(
            $snapshot->distance_km === null ? null : $snapshot->distance_km * 1000,
            $snapshot->moving_time_sec,
        );

        $decoded = $this->caller->call(
            kind: 'weekly_recap',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [
                'week_ending' => $snapshot->week_ending->toDateString(),
                'runs' => $snapshot->runs,
                'distance_km' => $snapshot->distance_km,
                'pace_sec_per_km' => $paceSecPerKm,
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
            options: new ChatCallOptions(temperature: 0.7, userId: $snapshot->user_id, maxTokens: 1500),
        );

        return (string) $decoded['narrative'];
    }
}
