<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\TrainingLoad;

final class TrainingLoadTool extends ActivityTool
{
    public function __construct(
        Activity $activity,
        ActivityDetail $detail,
        private readonly TrainingLoad $trainingLoad,
    ) {
        parent::__construct($activity, $detail);
    }

    public function name(): string
    {
        return 'get_training_load';
    }

    public function description(): string
    {
        return 'Kondisi beban latihan pengguna pada hari lari ini: acute_7d, chronic_42d, form, dan '
            .'form_status (fresh/optimal/fatigued/overreaching). Panggil sebelum menyarankan recovery '
            .'atau sesi berikutnya. Null kalau riwayat TRIMP-nya belum cukup.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $load = $this->trainingLoad->summary($this->activity->user, $this->asOf());

        return [
            'training_load' => $load === null ? null : [
                'acute_7d' => $load['atl_7d'],
                'chronic_42d' => $load['ctl_42d'],
                'form' => $load['form'],
                'form_status' => $load['form_status'],
            ],
        ];
    }
}
