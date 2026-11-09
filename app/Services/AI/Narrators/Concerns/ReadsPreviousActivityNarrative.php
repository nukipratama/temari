<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators\Concerns;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\NarratorContinuity;

/**
 * Continuity for the connected + chained per-activity narration: reads the previous activities'
 * same-kind Done narratives so each run continues the thread of the ones before it. Shared by
 * post-run speech and run insight.
 */
trait ReadsPreviousActivityNarrative
{
    /**
     * The given kind's Done contents for the user's most recent earlier activities
     * (by start_date_local) whose that-kind row is Done, newest first, up to
     * {@see NarratorContinuity::WINDOW}. Empty when no such predecessor exists
     * (first ever run, or it is not yet narrated), so the narrator opens standalone.
     *
     * @return list<string>
     */
    private function previousActivityNarratives(Activity $activity, ActivityDetail $detail, AnalysisType $kind): array
    {
        $startedAt = $detail->start_date_local;
        if ($startedAt === null) {
            return [];
        }

        $contents = Analysis::query()
            ->join('activities', 'activities.id', '=', 'ai_analyses.subject_id')
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('ai_analyses.subject_type', Activity::class)
            ->where('ai_analyses.analysis_type', $kind)
            ->whereNull('ai_analyses.discriminator')
            ->where('ai_analyses.status', AnalysisStatus::Done)
            ->where('activities.user_id', $activity->user_id)
            ->where('activities.id', '!=', $activity->id)
            ->where('activity_details.start_date_local', '<', $startedAt)
            ->orderByDesc('activity_details.start_date_local')
            ->limit(NarratorContinuity::WINDOW)
            ->pluck('ai_analyses.content')
            ->all();

        return array_values(array_filter($contents, is_string(...)));
    }
}
