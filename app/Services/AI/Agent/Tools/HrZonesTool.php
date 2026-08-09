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
        return 'Time spent per HR zone (percent and minutes), the intensity_label (light/moderate/heavy) '
            ."derived from that spread, plus this session's TRIMP. Empty if this run didn't record heart rate.";
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
                $hardZoneShare >= 50.0 => 'heavy',
                $hardZoneShare >= 20.0 => 'moderate',
                default => 'light',
            },
        ];
    }
}
