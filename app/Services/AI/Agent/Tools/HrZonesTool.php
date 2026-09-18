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
            ."derived from that spread, this session's TRIMP, and hr_drift (how much HR moved from the "
            .'first half to the second at a similar effort): bpm plus its own relation (up/down/flat) '
            .'-- there is no sign to read yourself. Empty if this run didn\'t record heart rate.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $summary = $this->summary();
        $zonePct = $summary->zonePct();
        $hardZoneShare = $summary->hardZoneShare();
        $hrDriftBpm = $summary->hrDriftBpm();

        return [
            'zone_pct' => $zonePct,
            'time_in_zone_min' => $summary->zoneMinutes(),
            'trimp' => $this->detail->trimp_edwards,
            'hr_drift' => $hrDriftBpm === null ? null : [
                'bpm' => abs($hrDriftBpm),
                'relation' => self::hrDriftRelation($hrDriftBpm),
            ],
            'intensity_label' => $zonePct === [] ? null : match (true) {
                $hardZoneShare >= 50.0 => 'heavy',
                $hardZoneShare >= 20.0 => 'moderate',
                default => 'light',
            },
        ];
    }

    private static function hrDriftRelation(float $bpm): string
    {
        return match (true) {
            $bpm > 0.0 => 'up',
            $bpm < 0.0 => 'down',
            default => 'flat',
        };
    }
}
