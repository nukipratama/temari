<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

final class TerrainTool extends ActivityTool
{
    public function name(): string
    {
        return 'get_terrain';
    }

    public function description(): string
    {
        return "This run's terrain: total elevation gain (meters), steepest climb (percent), and "
            .'grade-adjusted pace. Call this when the pace slows and you suspect a climb is the cause.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $summary = $this->summary();

        return [
            'elevation_gain_m' => $this->detail->total_elevation_gain,
            'max_grade_pct' => $summary->maxGradePct(),
            'gap_pace' => $summary->gapPace(),
        ];
    }
}
