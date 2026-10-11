<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Ingest\HydrationBacklog;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    /**
     * @param  Collection<int, PlannedSession>|null  $weekSessions  this week's rows in date order, when the caller already read them
     * @param  array{sessions_per_week: int, weekly_volume_km: float, long_run_km: float, long_run_cap_km: float, long_run_progression_cap_km: float, self_scaled: bool}|null  $baseline  {@see \App\Services\Run\Plan\TrainingBaseline::forUser()} as of `$today`, when the caller already built it
     */
    public function forUser(User $user, Carbon $today, ?Collection $weekSessions = null, ?array $baseline = null): BriefingContext
    {
        return $this->memo[$user->id.'|'.$today->toDateString()] ??= $this->build($user, $today, $weekSessions, $baseline);
    }

    /**
     * @param  Collection<int, PlannedSession>|null  $weekSessions
     * @param  array{sessions_per_week: int, weekly_volume_km: float, long_run_km: float, long_run_cap_km: float, long_run_progression_cap_km: float, self_scaled: bool}|null  $baseline
     */
    private function build(User $user, Carbon $today, ?Collection $weekSessions, ?array $baseline): BriefingContext
    {
        $loadPending = $this->hydrationBacklog->recentLoadAwaitsScoring($user->id, $today);

        return BriefingContext::forUser(
            $user,
            $today,
            $loadPending ? null : $this->trainingLoad->summary($user, $today),
            historyLoading: $loadPending,
            weekSessions: $weekSessions,
            baseline: $baseline,
        );
    }
}
