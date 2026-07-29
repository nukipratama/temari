<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

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
            'zone_pct' => $summary->zonePct(),
            'time_in_zone_min' => $summary->zoneMinutes(),
            'trimp' => $this->detail->trimp_edwards,
        ];
    }
}
