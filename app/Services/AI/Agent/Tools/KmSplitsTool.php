<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Services\Run\Metrics\PaceConsistency;

final class KmSplitsTool extends ActivityTool
{
    public function name(): string
    {
        return 'get_km_splits';
    }

    public function description(): string
    {
        return 'Split per km (dengan avg_hr per km kalau ada), sisa jarak setelah km bulat terakhir, '
            .'pola negative split, dan seberapa rata pace-nya. Panggil untuk cerita pacing.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $summary = $this->summary();

        return [
            'per_km' => $summary['per_km'] ?? null,
            'finish_partial' => $summary['partial_split'] ?? null,
            'negative_split' => $summary['negative_split'] ?? null,
            'pace_consistency' => PaceConsistency::label($summary['pace_variability_sec'] ?? null),
        ];
    }
}
