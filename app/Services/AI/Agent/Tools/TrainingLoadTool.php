<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\User;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

final class TrainingLoadTool extends UserTool
{
    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly TrainingLoad $trainingLoad,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_training_load';
    }

    public function description(): string
    {
        return "The user's training load state: acute_7d, chronic_42d, form, and form_status "
            .'(fresh/optimal/fatigued/overreaching). Call this before suggesting recovery or the '
            .'next session. If training_load is missing, their TRIMP history isn\'t enough yet.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $load = $this->trainingLoad->summary($this->user, $this->asOf);

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
