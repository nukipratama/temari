<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\User;
use App\Services\AI\HistoryNarrationGate;
use App\Services\Run\Metrics\LoadBalance;
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
        return "The user's running load: acute_7d (short-term load, the last 7 days), chronic_42d "
            .'(long-term load, about six weeks) and load_balance (fresh/steady/heavy, long-term '
            .'minus short-term -- no sign to read, that\'s the call already made). These count '
            .'running only. Call this before '
            ."suggesting recovery or the next session. If training_load is missing, their TRIMP "
            .'history isn\'t enough yet, or (history_loading: true) it is still being imported.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        // History still hydrating from a fresh connect: CTL/ATL/form would be
        // computed off an incomplete past — see docs/decisions/history-narrates-on-demand.md.
        if (app(HistoryNarrationGate::class)->awaitsOlderHydration($this->user->id, $this->asOf)) {
            return ['training_load' => null, 'history_loading' => true];
        }

        $load = $this->trainingLoad->summary($this->user, $this->asOf);

        return [
            'training_load' => $load === null ? null : [
                'acute_7d' => $load['atl_7d'],
                'chronic_42d' => $load['ctl_42d'],
                'load_balance' => LoadBalance::fromStored($load['form_status'])?->value,
            ],
        ];
    }
}
