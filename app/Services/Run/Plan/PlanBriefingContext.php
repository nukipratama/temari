<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\User;
use App\Services\Run\Ingest\HydrationBacklog;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
use Illuminate\Support\Carbon;

/**
 * The {@see BriefingContext} the Plan tab reads, built once per athlete and
 * day within a request: {@see PlanPageAssembler} and {@see CurrentWeekKm} both
 * ask for it, and a deferred Plan render runs both. Bound `scoped()` in
 * AppServiceProvider so they share one instance.
 */
final class PlanBriefingContext
{
    /** @var array<string, BriefingContext> */
    private array $memo = [];

    public function __construct(
        private readonly HydrationBacklog $hydrationBacklog,
        private readonly TrainingLoad $trainingLoad,
    ) {
    }

    public function forUser(User $user, Carbon $today): BriefingContext
    {
        return $this->memo[$user->id.'|'.$today->toDateString()] ??= $this->build($user, $today);
    }

    private function build(User $user, Carbon $today): BriefingContext
    {
        $loadPending = $this->hydrationBacklog->recentLoadAwaitsScoring($user->id, $today);

        return BriefingContext::forUser(
            $user,
            $today,
            $loadPending ? null : $this->trainingLoad->summary($user, $today),
            historyLoading: $loadPending,
        );
    }
}
