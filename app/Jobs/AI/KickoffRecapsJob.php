<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Actions\AI\KickoffMonthlyRecaps;
use App\Actions\AI\KickoffWeeklyRecaps;
use App\Actions\AI\RequestTodaysBriefing;
use App\Jobs\Strava\HydrateBacklogForUserJob;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\HistoryNarrationGate;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * The last link of the first-connect chain: weekly + monthly recap and Trends
 * kickoff for a single user, so a new athlete's history narrates on day one
 * instead of waiting for the Monday / 1st-of-month sweep. Recap and Trends
 * reads touch only rows the backfill already wrote, so those bill no Strava
 * budget; the one exception is the immediate {@see HydrateBacklogForUserJob}
 * this dispatches, bounded by the same background read headroom the
 * `strava:hydrate-backlog` cron shares, so the backlog drain starts now
 * instead of waiting for the next tick. The kickoff actions skip every Done
 * recap, so a re-run bills nothing.
 *
 * Being last, it is also where `users.backfilled_at` is stamped — the marker
 * nothing else could supply, since the chain's own position is unreadable from
 * outside it — and where the first week is re-sized against the history that
 * just landed, then narrated, if onboarding already wrote a plan. Today's
 * briefing, asked for at signup against a history that had not arrived yet, is
 * re-requested here so it reads the runs instead.
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
        PlanNarrationRequester $planNarration,
        AnalysisService $analysis,
        Periodizer $periodizer,
        RequestTodaysBriefing $briefing,
        HistoryNarrationGate $history,
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

        $this->kickoffTrendReads($analysis, $user, $history);

        if (PlannedSession::query()->where('user_id', $user->id)->exists()) {
            $periodizer->regenerate($user);
            $planNarration->requestForFirstWeek($user, Carbon::today());
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
    private function kickoffTrendReads(AnalysisService $analysis, User $user, HistoryNarrationGate $history): void
    {
        if ($history->awaitsFullHydration($user->id)) {
            return;
        }

        $analysis->requestTrendReads($user);
    }
}
