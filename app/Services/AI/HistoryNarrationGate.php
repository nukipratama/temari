<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Actions\AI\RecentlyActiveUsers;
use App\Models\User;
use App\Services\Run\Ingest\HydrationBacklog;
use Illuminate\Support\Carbon;

/**
 * Decides which runs the LLM narrates by itself and which wait to be asked for.
 *
 * A run the athlete did before connecting Strava is history: inside the last {@see
 * RecentlyActiveUsers::ACTIVE_WINDOW_DAYS} days it still narrates on ingest, anything older is
 * filled deterministically and left to the page's "Try again".
 *
 * Any narration waits until every older run within past-you's reach has finished hydrating, because
 * the narrator's tools read resolved metrics.
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
        if (!$startedAt instanceof Carbon) {
            return false;
        }

        $connectedAt = $this->backlog->connectedAt($user->id);

        return $connectedAt instanceof Carbon && $startedAt->lt($connectedAt);
    }

    /**
     * Whether a historical run is still recent enough to narrate automatically
     * on ingest, rather than waiting for the on-demand "Try again".
     */
    public function narratesAutomatically(?Carbon $startedAt): bool
    {
        return $startedAt instanceof Carbon && $startedAt->gte(RecentlyActiveUsers::windowStart());
    }

    /**
     * Whether a manual trigger on this subject must wait: a historical run whose
     * older history is still hydrating.
     */
    public function awaitsHydration(User $user, AnalysisType $type, int $subjectId): bool
    {
        $startedAt = $this->ages->runDateForSubject($type, $subjectId);

        if (!$startedAt instanceof Carbon || ! $this->isHistorical($user, $startedAt)) {
            return false;
        }

        return $this->backlog->olderHistoryHydrating($user->id, $startedAt);
    }
}
