<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Actions\AI\KickoffMonthlyRecaps;
use App\Actions\AI\KickoffWeeklyRecaps;
use App\Actions\AI\RequestTodaysBriefing;
use App\Enums\PlanRegenerationReason;
use App\Jobs\Run\RegeneratePlanJob;
use App\Jobs\Strava\HydrateBacklogForUserJob;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\Run\Ingest\HydrationBacklog;
use App\Services\AI\NarrationOrigin;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The last link of the first-connect chain: weekly + monthly recap and Trends
 * kickoff for a single user, so a new athlete's history narrates on day one.
 * Recap and Trends reads touch only rows the backfill already wrote; the one
 * Strava-billing exception is the immediate {@see HydrateBacklogForUserJob} this
 * dispatches, bounded by the same background read headroom as the
 * `strava:hydrate-backlog` cron. The kickoff actions skip every Done recap, so a
 * re-run bills nothing.
 *
 * Being last, it is where `users.backfilled_at` is stamped, where the first week
 * is re-sized against the landed history if onboarding already wrote a plan, and
 * where today's briefing is re-requested so it reads the runs.
 */
class KickoffRecapsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
    }

    public function handle(
        KickoffWeeklyRecaps $weekly,
        KickoffMonthlyRecaps $monthly,
        AnalysisService $analysis,
        Periodizer $periodizer,
        RequestTodaysBriefing $briefing,
        HydrationBacklog $backlog,
    ): void {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Ingest);

        $weekly($this->userId);
        $monthly($this->userId);

        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        HydrateBacklogForUserJob::dispatch($user->id);

        $user->markBackfilled();

        $briefing->afterBackfill($user);

        $this->kickoffTrendReads($analysis, $user, $backlog);

        if (PlannedSession::query()->where('user_id', $user->id)->exists()) {
            try {
                $periodizer->regenerate($user);
            } catch (LockTimeoutException) {
                RegeneratePlanJob::dispatch($user->id, PlanRegenerationReason::Onboarding)->afterCommit();
            }
        }
    }

    /**
     * The Trends read's own daily cron only reaches athletes who already
     * existed when it last ran, so it is kicked off here too for a same-day
     * first read. Held while this athlete's backlog is still hydrating — a
     * load/fitness/form read of an incomplete past — and requested again,
     * exactly once, by {@see \App\Actions\AI\SettleEarlyNarrationAction} the
     * moment the drain empties.
     */
    private function kickoffTrendReads(AnalysisService $analysis, User $user, HydrationBacklog $backlog): void
    {
        if ($backlog->awaitsFullHydration($user->id)) {
            return;
        }

        $analysis->requestTrendReads($user);
    }
}
