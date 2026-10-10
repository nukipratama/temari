<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanRegenerationReason;
use App\Events\PlanRegenerated;
use App\Jobs\Run\RegeneratePlanJob;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;

final readonly class PlanRegenerationService
{
    public function __construct(
        private Periodizer $periodizer,
        private PlanRegenerateCooldown $regenerateCooldown,
    ) {
    }

    public function regenerateForRequest(User $user, PlanRegenerationReason $reason): bool
    {
        $today = Carbon::today();

        try {
            $this->periodizer->regenerate($user, $today, Periodizer::REQUEST_LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            if ($reason === PlanRegenerationReason::Manual) {
                $this->regenerateCooldown->start($user);
            }

            RegeneratePlanJob::dispatch($user->id, $reason)->afterCommit();

            return false;
        }

        PlanRegenerated::dispatch($user, $today, $reason);
        if ($reason === PlanRegenerationReason::Manual) {
            $this->regenerateCooldown->start($user);
        }

        return true;
    }

    public function requestNarrationAfterQueuedRegeneration(User $user, PlanRegenerationReason $reason): void
    {
        PlanRegenerated::dispatch($user, Carbon::today(), $reason);
    }
}
