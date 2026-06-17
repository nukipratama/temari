<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators\Concerns;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;

/**
 * Continuity for the connected + chained per-activity narration: reads the
 * previous activity's same-kind Done narrative so each run continues the thread
 * of the one before it. Shared by the per-activity narrators (post-run speech +
 * run insight). The per-activity chain (kickoff + AnalyzeActivityJob group
 * propagation, oldest first by start_date_local) guarantees the predecessor's
 * group is Done before this activity narrates, so steady-state always sees the
 * prior thread; backfill fills it in chronologically.
 */
trait ReadsPreviousActivityNarrative
{
    /**
     * The given kind's Done content for the user's most recent earlier activity
     * (by start_date_local) whose that-kind row is Done. Null when no such
     * predecessor exists (first ever run, or it is not yet narrated), so the
     * narrator opens standalone.
     */
    private function previousActivityNarrative(Activity $activity, ActivityDetail $detail, AnalysisType $kind): ?string
    {
        $startedAt = $detail->start_date_local;
        if ($startedAt === null) {
            return null;
        }

        $previousActivityId = Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activities.user_id', $activity->user_id)
            ->where('activities.id', '!=', $activity->id)
            ->where('activity_details.start_date_local', '<', $startedAt)
            ->whereHas('analyses', fn ($query) => $query
                ->where('analysis_type', $kind)
                ->where('status', AnalysisStatus::Done))
            ->orderByDesc('activity_details.start_date_local')
            ->value('activities.id');

        if ($previousActivityId === null) {
            return null;
        }

        return Analysis::query()
            ->forSubject(Activity::class, (int) $previousActivityId, $kind)
            ->where('status', AnalysisStatus::Done)
            ->value('content');
    }
}
