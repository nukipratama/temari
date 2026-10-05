<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\TimeTrial;
use App\Services\Run\Plan\TimeTrialService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

#[Signature('plan:settle-time-trials')]
#[Description('Count each finished time trial as evidence, or ask the athlete once')]
class TimeTrialSettleCommand extends Command
{
    public const int LOOKBACK_DAYS = 7;

    public function handle(TimeTrialService $trials): int
    {
        $sessions = PlannedSession::query()
            ->where('prescription_race_context->kind', TimeTrial::KIND)
            ->whereNull('time_trial_outcome')
            ->whereBetween('date', [Carbon::today()->subDays(self::LOOKBACK_DAYS)->toDateString(), Carbon::yesterday()->toDateString()])
            ->whereIn('user_id', User::query()->notDemo()->select('id'))
            ->with('user')
            ->orderBy('date')
            ->get();

        $settled = 0;
        $failed = 0;
        foreach ($sessions as $session) {
            try {
                $settled += $trials->settle($session) === null ? 0 : 1;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        $this->info("Settled {$settled} time trial(s).");

        return $failed > 0 && $settled === 0 ? self::FAILURE : self::SUCCESS;
    }
}
