<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\User;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Support\Carbon;

final class TrainingPacesTool extends UserTool
{
    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_training_paces';
    }

    public function description(): string
    {
        return 'Pace latihan pengguna dalam detik per km, diturunkan dari VDOT-nya: easy, marathon, '
            .'threshold, interval. Panggil kalau mau menyarankan pace yang konkret. Semua null kalau '
            .'riwayat PR-nya belum cukup buat mengestimasi VDOT.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($this->user));

        return [
            'easy_pace_sec' => $paces['easy'] ?? null,
            'marathon_pace_sec' => $paces['marathon'] ?? null,
            'threshold_pace_sec' => $paces['threshold'] ?? null,
            'interval_pace_sec' => $paces['interval'] ?? null,
        ];
    }
}
