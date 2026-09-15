<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Decides which runs the LLM narrates by itself and which wait to be asked for.
 *
 * A run the athlete did before they connected Strava is history — it exists only
 * because the first-connect backfill imported it. The backfill hydrates its
 * streams and metrics, but its narration is only ever read if someone opens that
 * run's detail page, so it is filled deterministically and an LLM read is left
 * to the page's own "Try again".
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
    public function __construct(private readonly BackfillAgeGate $ages)
    {
    }

    public function isHistorical(User $user, ?Carbon $startedAt): bool
    {
        if ($startedAt === null) {
            return false;
        }

        $connectedAt = StravaConnection::query()->where('user_id', $user->id)->value('created_at');

        return $connectedAt !== null && $startedAt->lt(Carbon::parse($connectedAt));
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

        return Activity::query()
            ->awaitingHydration()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activities.user_id', $user->id)
            ->where('activity_details.start_date_local', '<', $startedAt)
            ->where('activity_details.start_date_local', '>=', $this->ages->cutoff())
            ->exists();
    }
}
