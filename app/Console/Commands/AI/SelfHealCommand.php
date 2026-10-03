<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Services\AI\NarrationGate;
use App\Services\AI\MaintainerAlerter;
use App\Services\AI\SelfHealer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

#[Signature('ai:self-heal')]
#[Description('Hourly safety net: re-kick the earliest stalled AI block per user (chains + card/PR/briefing/profile/trend narration), under a retry budget')]
class SelfHealCommand extends Command
{
    public function handle(NarrationGate $gate, MaintainerAlerter $alerter, SelfHealer $healer): int
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Recovery);

        // Detect a pause on/off transition before the early-exit, so both entering
        // a pause (healthy -> paused) and resuming (paused -> healthy) push an alert
        // once, with the reason. Runs hourly regardless of the current pause state.
        $liftedPauseStartedAt = $alerter->syncPauseState($gate->pauseReason());

        // Nothing this run can dispatch would bill while generation is paused
        // (cost ceiling / AI off / Azure unset) - every request() would no-op -
        // so skip the per-user queries until it clears.
        if ($gate->generationPaused()) {
            $this->info('Skipped: AI generation is paused (cost ceiling / AI off / Azure unset).');

            return self::SUCCESS;
        }

        $resumed = ($liftedPauseStartedAt === null ? 0 : $healer->retryFailedDuringPause($liftedPauseStartedAt))
            + $healer->run();

        $this->info("Resumed {$resumed} blocks.");

        return self::SUCCESS;
    }
}
