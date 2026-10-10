<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\AI\RecentlyActiveUsers;
use App\Enums\PlanRegenerationReason;
use App\Events\PlanRegenerated;
use App\Services\AI\PlanNarrationRequester;

final readonly class RequestPlanNarrationOnPlanRegenerated
{
    public function __construct(
        private PlanNarrationRequester $narration,
        private RecentlyActiveUsers $activeUsers,
    ) {
    }

    public function handle(PlanRegenerated $event): void
    {
        $user = $event->user;
        $today = $event->today;

        match ($event->reason) {
            PlanRegenerationReason::Manual => $this->narration->requestForCurrentWeek($user, $today),
            PlanRegenerationReason::Onboarding => $user->refresh()->backfilled_at !== null
                ? $this->narration->requestForFirstWeek($user, $today)
                : null,
            PlanRegenerationReason::Reconciliation => $this->activeUsers->includes($user)
                ? $this->narration->requestForCurrentWeek($user, $today)
                : null,
            PlanRegenerationReason::Settings => $user->is_demo
                ? $this->narration->ensureDemoFilled($user)
                : $this->narration->requestForCurrentWeekUnlessCoolingDown($user, $today),
        };
    }
}
