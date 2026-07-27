<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;

final class TrainingPacesTool extends ActivityTool
{
    public function __construct(
        Activity $activity,
        ActivityDetail $detail,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
    ) {
        parent::__construct($activity, $detail);
    }

    public function name(): string
    {
        return 'get_training_paces';
    }

    public function description(): string
    {
        return 'Pace latihan pengguna (detik per km) dari VDOT-nya: easy dan threshold. Panggil kalau '
            .'mau menyarankan pace easy yang konkret. Null kalau riwayat PR-nya belum cukup.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $paces = $this->paceCalculator->fromVdotResult(
            $this->vdotEstimator->estimate($this->activity->user),
        );

        return [
            'easy_pace_sec' => $paces['easy'] ?? null,
            'threshold_pace_sec' => $paces['threshold'] ?? null,
        ];
    }
}
