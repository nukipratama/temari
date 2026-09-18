<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Actions\AI\RecentlyActiveUsers;
use App\Models\User;
use App\Services\Run\Story\PastYouMatcher;
use Illuminate\Support\Carbon;

/**
 * Decides which runs the LLM narrates by itself and which wait to be asked for.
 *
 * A run the athlete did before they connected Strava is history — it exists only
 * because the first-connect backfill imported it. The backfill hydrates its
 * streams and metrics; a historical run inside the last
 * {@see RecentlyActiveUsers::ACTIVE_WINDOW_DAYS} days still narrates by itself on
 * ingest, the same window {@see \App\Jobs\AI\NarrateOnReturnJob} applies to a
 * returning athlete's pending runs, so a day-one backfill's cost is bounded and
 * identical regardless of how much history it imports. Anything older is filled
 * deterministically and an LLM read is left to the page's own "Try again".
 *
 * Any narration, automatic or on-demand, waits until every older run within
 * past-you's reach has finished hydrating, because the narrator's baseline,
 * recent-runs and past-you tools read resolved metrics: narrating over a
 * half-hydrated history writes a thin story that nothing re-narrates.
 *
 * @see docs/decisions/history-narrates-on-demand.md
 */
class HistoryNarrationGate
{
    public function __construct(
        private readonly BackfillAgeGate $ages,
        private readonly HydrationBacklog $backlog,
    ) {
    }

    public function isHistorical(User $user, ?Carbon $startedAt): bool
    {
        if ($startedAt === null) {
            return false;
        }

        $connectedAt = $this->backlog->connectedAt($user->id);

        return $connectedAt !== null && $startedAt->lt($connectedAt);
    }

    /**
     * Whether a historical run is still recent enough to narrate automatically
     * on ingest, rather than waiting for the on-demand "Try again".
     */
    public function narratesAutomatically(?Carbon $startedAt): bool
    {
        return $startedAt !== null && $startedAt->gte(RecentlyActiveUsers::windowStart());
    }

    /**
     * Whether a manual trigger on this subject must wait: a historical run whose
     * older history is still hydrating.
     */
    public function awaitsHydration(User $user, AnalysisType $type, int $subjectId): bool
    {
        $startedAt = $this->ages->runDateForSubject($type, $subjectId);

        if ($startedAt === null || ! $this->isHistorical($user, $startedAt)) {
            return false;
        }

        return $this->olderHistoryHydrating($user->id, $startedAt);
    }

    /**
     * Whether narrating a run automatically now would read a history still
     * filling in. Bounded by the grace window after the athlete connected: past
     * it the run narrates whatever has landed, so a stuck drain cannot hold
     * narration forever.
     */
    public function awaitsOlderHydration(int $userId, ?Carbon $startedAt): bool
    {
        $connectedAt = $this->backlog->connectedAt($userId);
        $graceHours = (int) config('ai.recap_hydration_grace_hours', 48);

        if ($startedAt === null || $connectedAt === null || Carbon::now()->gte($connectedAt->addHours($graceHours))) {
            return false;
        }

        return $this->olderHistoryHydrating($userId, $startedAt);
    }

    /**
     * A run inside past-you's reach before $startedAt still awaits hydration.
     */
    private function olderHistoryHydrating(int $userId, Carbon $startedAt): bool
    {
        return $this->backlog->awaitsHydrationBefore(
            $userId,
            $startedAt,
            $startedAt->copy()->subDays(PastYouMatcher::MAX_GAP_DAYS),
        );
    }

    /**
     * Whether ANY of this athlete's runs, at any age, still await hydration —
     * wider than {@see self::awaitsOlderHydration()}'s past-you-bounded reach.
     * The profile voice reads the athlete's whole history (lifetime stats,
     * full PR table, all-time plan adherence), so a run outside past-you's
     * 365-day window can still be exactly the one it would misread. Bounded
     * by the same connect-anchored grace window, so a stuck drain cannot hold
     * it forever and a long-connected athlete is never affected.
     */
    public function awaitsFullHydration(int $userId): bool
    {
        $connectedAt = $this->backlog->connectedAt($userId);
        $graceHours = (int) config('ai.recap_hydration_grace_hours', 48);

        if ($connectedAt === null || Carbon::now()->gte($connectedAt->addHours($graceHours))) {
            return false;
        }

        return $this->backlog->awaitingHydration([$userId])->exists();
    }
}
