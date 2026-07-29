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
        return 'Medan lari ini: total elevation gain (meter), tanjakan tercuram (persen), dan '
            .'grade-adjusted pace. Panggil kalau pace melambat dan kamu curiga tanjakan penyebabnya.';
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
