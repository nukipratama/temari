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
        return 'Sebaran waktu per HR zone (persen dan menit), intensity_label (ringan/sedang/berat) '
            .'turunan dari sebaran itu, plus TRIMP sesi ini. Kosong kalau lari ini tidak merekam heart rate.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $summary = $this->summary();
        $zonePct = $summary->zonePct();
        $hardZoneShare = $summary->hardZoneShare();

        return [
            'zone_pct' => $zonePct,
            'time_in_zone_min' => $summary->zoneMinutes(),
            'trimp' => $this->detail->trimp_edwards,
            'intensity_label' => $zonePct === [] ? null : match (true) {
                $hardZoneShare >= 50.0 => 'berat',
                $hardZoneShare >= 20.0 => 'sedang',
                default => 'ringan',
            },
        ];
    }
}
