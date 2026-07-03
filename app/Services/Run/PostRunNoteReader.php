<?php

declare(strict_types=1);

namespace App\Services\Run;

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Carbon;

/**
 * Composite read for a run's post-run note: the mood comes from the
 * post-run {@see StoryLine}, the one-liner from the {@see Analysis} row
 * carrying the {@see AnalysisType::PostRunSpeech} narration once it is Done.
 *
 * The three page controllers (Riwayat / HariIni / Kalender) used to hand-roll
 * this read; centralising it keeps the produced shapes from drifting.
 */
class PostRunNoteReader
{
    /**
     * Mood + one-liner for a single activity, or null when either half of the
     * note is not ready yet.
     *
     * @return array{oneline: string, mood: string}|null
     */
    public function forActivity(int $activityId): ?array
    {
        $mood = StoryLine::query()
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->where('activity_id', $activityId)
            ->value('mood');

        $speech = Analysis::query()
            ->where('subject_type', Activity::class)
            ->where('analysis_type', AnalysisType::PostRunSpeech)
            ->where('status', AnalysisStatus::Done)
            ->where('subject_id', $activityId)
            ->value('content');

        if ($mood === null || ! is_string($speech) || $speech === '') {
            return null;
        }

        return ['oneline' => $speech, 'mood' => $mood];
    }

    /**
     * Mood + one-liner for a batch of activities, keyed by activity id. Rows
     * whose note is not ready are omitted (same skip rule as
     * {@see self::forActivity()}).
     *
     * @param  array<int, int>  $activityIds
     * @return array<int, array{oneline: string, mood: string}>
     */
    public function forActivities(array $activityIds): array
    {
        if ($activityIds === []) {
            return [];
        }

        $moodByActivity = StoryLine::query()
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->whereIn('activity_id', $activityIds)
            ->pluck('mood', 'activity_id');

        $speechByActivity = Analysis::query()
            ->where('subject_type', Activity::class)
            ->where('analysis_type', AnalysisType::PostRunSpeech)
            ->where('status', AnalysisStatus::Done)
            ->whereIn('subject_id', $activityIds)
            ->pluck('content', 'subject_id');

        $notes = [];
        foreach ($activityIds as $id) {
            $speech = $speechByActivity->get($id);
            $mood = $moodByActivity->get($id);
            if ($speech === null || $speech === '' || $mood === null) {
                continue;
            }
            $notes[$id] = ['oneline' => $speech, 'mood' => $mood];
        }

        return $notes;
    }

    /**
     * Persisted post-run mood per activity, keyed by activity id, independent of
     * whether the speech is ready. Lets list/dashboard surfaces show the same
     * mood the backend already computed instead of a frontend heuristic during
     * the window before the post-run speech lands.
     *
     * @param  array<int, int>  $activityIds
     * @return array<int, string>
     */
    public function moodsFor(array $activityIds): array
    {
        if ($activityIds === []) {
            return [];
        }

        return StoryLine::query()
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->whereIn('activity_id', $activityIds)
            ->pluck('mood', 'activity_id')
            ->all();
    }

    /**
     * The post-run one-liner stored on today's {@see StoryLine} for this user,
     * read straight from the StoryLine's own `speech` column (not the Analysis
     * row). Null when the user has no post-run line dated today.
     */
    public function speechForToday(int $userId): ?string
    {
        return StoryLine::query()
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->whereHas('activity', fn ($q) => $q
                ->where('user_id', $userId)
                ->whereHas('detail', fn ($q) => $q->whereDate('start_date_local', Carbon::today())))
            ->orderByDesc('id')
            ->value('speech');
    }
}
