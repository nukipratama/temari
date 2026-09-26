<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanRegenerationReason;
use App\Jobs\Run\RegeneratePlanJob;
use App\Models\User;
use App\Services\AI\PlanNarrationRequester;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;

final readonly class PlanRegenerationService
{
    public function __construct(
        private Periodizer $periodizer,
        private PlanNarrationRequester $narrationRequester,
    ) {
    }

    public function regenerateForRequest(User $user, PlanRegenerationReason $reason): bool
    {
        $today = Carbon::today();

        try {
            $this->periodizer->regenerate($user, $today, Periodizer::REQUEST_LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            if ($reason === PlanRegenerationReason::Manual) {
                $this->narrationRequester->startRegenerateCooldown($user);
            }

            RegeneratePlanJob::dispatch($user->id, $reason)->afterCommit();

            return false;
        }

        $this->requestNarration($user, $reason, $today);
        if ($reason === PlanRegenerationReason::Manual) {
            $this->narrationRequester->startRegenerateCooldown($user);
        }

        return true;
    }

    public function requestNarrationAfterQueuedRegeneration(User $user, PlanRegenerationReason $reason): void
    {
        $this->requestNarration($user, $reason, Carbon::today());
    }

    private function requestNarration(User $user, PlanRegenerationReason $reason, Carbon $today): void
    {
        if ($reason === PlanRegenerationReason::Manual) {
            $this->narrationRequester->requestForCurrentWeek($user, $today);

            return;
        }

        if ($reason === PlanRegenerationReason::Onboarding) {
            if ($user->refresh()->backfilled_at !== null) {
                $this->narrationRequester->requestForFirstWeek($user, $today);
            }

            return;
        }

        if ($user->is_demo) {
            $this->narrationRequester->ensureDemoFilled($user, $today);
        } else {
            $this->narrationRequester->requestForCurrentWeekUnlessCoolingDown($user, $today);
        }
    }
}
