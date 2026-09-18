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
        return "This run's terrain: total elevation gain (meters), the steepest sustained grade -- "
            .'pct plus its own relation (climb/descent/flat, there is no sign to read yourself) -- '
            .'and grade-adjusted pace. Call this when the pace slows and you suspect a climb is the '
            .'cause.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $summary = $this->summary();
        $maxGradePct = $summary->maxGradePct();

        return [
            'elevation_gain_m' => $this->detail->total_elevation_gain,
            'max_grade' => $maxGradePct === null ? null : [
                'pct' => abs($maxGradePct),
                'relation' => self::gradeRelation($maxGradePct),
            ],
            'gap_pace' => $summary->gapPace(),
        ];
    }

    private static function gradeRelation(float $pct): string
    {
        return match (true) {
            $pct > 0.0 => 'climb',
            $pct < 0.0 => 'descent',
            default => 'flat',
        };
    }
}
