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
        return "The user's training paces in seconds per km, derived from their VDOT: easy, "
            .'marathon, threshold, interval. Call this when you want to suggest a concrete pace. '
            .'If the paces don\'t show up at all, their PR history isn\'t enough yet to estimate VDOT.';
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
