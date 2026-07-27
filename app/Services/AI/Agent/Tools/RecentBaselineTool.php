<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\RunBaseline;

final class RecentBaselineTool extends ActivityTool
{
    public function __construct(
        Activity $activity,
        ActivityDetail $detail,
        private readonly RunBaseline $baseline,
    ) {
        parent::__construct($activity, $detail);
    }

    public function name(): string
    {
        return 'get_recent_baseline';
    }

    public function description(): string
    {
        return 'Rata-rata 28 hari terakhir milik pengguna sampai sebelum lari ini (pace, HR, decoupling), '
            .'lari ini sendiri tidak ikut dihitung. Panggil kalau mau bilang sesi ini lebih '
            .'cepat/lambat/berat dari biasanya. Null kalau riwayatnya masih tipis.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        return [
            'recent_baseline_28d' => $this->baseline->forUserAsOf(
                $this->activity->user_id,
                $this->asOf(),
                $this->activity->id,
            ),
        ];
    }
}
