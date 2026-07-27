<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Services\AI\Context\ActivityNarrationContext;

final class RunSummaryTool extends ActivityTool
{
    public function name(): string
    {
        return 'get_run_summary';
    }

    public function description(): string
    {
        return 'Angka pokok sesi ini: jarak, durasi, HR rata-rata dan maksimum, cadence. Mulai dari sini.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        return [
            'distance_km' => ActivityNarrationContext::fromDetail($this->detail)->distanceKm(2),
            'moving_time_sec' => $this->detail->moving_time,
            'avg_hr' => $this->detail->average_heartrate,
            'max_hr' => $this->detail->max_heartrate,
            'avg_cadence_spm' => $this->detail->average_cadence !== null
                ? (int) round((float) $this->detail->average_cadence * 2)
                : null,
        ];
    }
}
