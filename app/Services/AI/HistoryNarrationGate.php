<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Actions\AI\RecentlyActiveUsers;
use App\Models\User;
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
 * That on-demand read waits until every older run inside the backfill window
 * has finished hydrating, because the narrator's baseline and recent-runs tools
 * read resolved metrics: narrating over a half-hydrated history writes a thin
 * story that nothing re-narrates.
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
     * Whether a manual trigger on this subject must wait: a historical run with
     * an older, still-unhydrated run inside the backfill window.
     */
    public function awaitsHydration(User $user, AnalysisType $type, int $subjectId): bool
    {
        $startedAt = $this->ages->runDateForSubject($type, $subjectId);

        if ($startedAt === null || ! $this->isHistorical($user, $startedAt)) {
            return false;
        }

        return $this->backlog->awaitingHydration([$user->id])
            ->where('activity_details.start_date_local', '<', $startedAt)
            ->where('activity_details.start_date_local', '>=', $this->ages->cutoff())
            ->exists();
    }
}
