<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Services\Run\Metrics\StreamSummary;

final class HrZonesTool extends ActivityTool
{
    public function name(): string
    {
        return 'get_hr_zones';
    }

    public function description(): string
    {
        return 'Sebaran waktu per HR zone (persen dan menit) plus TRIMP sesi ini. '
            .'Kosong kalau lari ini tidak merekam heart rate.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $summary = $this->summary();

        return [
            'zone_pct' => StreamSummary::zonePct($summary),
            'time_in_zone_min' => $summary['time_in_zone_min'] ?? null,
            'trimp' => $this->detail->trimp_edwards,
        ];
    }
}
