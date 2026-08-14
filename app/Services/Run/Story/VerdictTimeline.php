<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Override;

class VerdictTimeline implements VerdictNarrator
{
    public const DEFAULT_LIMIT = 8;

    /**
     * The user's most recent narrated runs, newest first.
     *
     * Every filter is in the query so the LIMIT can be too. It used to load the
     * user's entire post-run history — hydrating each activity detail's
     * `stream_summary` JSON along with it — sort in PHP, and slice five off the
     * end. Both briefing narrators call this, twice a day.
     *
     * The three conditions are what the PHP loop used to enforce implicitly:
     * an un-ingested stub was dropped because {@see \App\Models\Scopes\AnalyzedScope}
     * nulled the `activity` relation, a detail with no start date was skipped after
     * hydration, and a run whose speech was missing or blank was skipped after a
     * second query. The scope does not reach a join from `story_lines`, so
     * `analyzed_at` is asserted here directly.
     *
     * @return list<VerdictTimelineItem>
     */
    #[Override]
    public function recent(User $user, int $limit = self::DEFAULT_LIMIT): array
    {
        /** @var Collection<int, StoryLine> $lines */
        $lines = StoryLine::query()
            ->select('story_lines.*')
            ->join('activities', 'activities.id', '=', 'story_lines.activity_id')
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('story_lines.user_id', $user->id)
            ->where('story_lines.kind', StoryLine::KIND_POST_RUN)
            ->whereNotNull('activities.analyzed_at')
            ->whereNotNull('activity_details.start_date_local')
            ->whereExists(fn (QueryBuilder $query) => $query
                ->from('ai_analyses')
                ->whereColumn('ai_analyses.subject_id', 'story_lines.activity_id')
                ->where('ai_analyses.subject_type', Activity::class)
                ->where('ai_analyses.analysis_type', AnalysisType::PostRunSpeech->value)
                ->where('ai_analyses.status', AnalysisStatus::Done->value)
                ->whereNotNull('ai_analyses.content')
                ->where('ai_analyses.content', '!=', ''))
            // Column-limited so the run's stream_summary blob is never hydrated;
            // only these four fields are read below.
            ->with(['activity.detail' => fn ($query) => $query->select(
                'id',
                'activity_id',
                'start_date_local',
                'distance',
                'trimp_edwards',
                'elapsed_time',
            )])
            ->orderByDesc('activity_details.start_date_local')
            ->limit($limit)
            ->get();

        if ($lines->isEmpty()) {
            return [];
        }

        $speechByActivity = Analysis::query()
            ->where('subject_type', Activity::class)
            ->where('analysis_type', AnalysisType::PostRunSpeech)
            ->where('status', AnalysisStatus::Done)
            ->whereIn('subject_id', $lines->pluck('activity_id')->all())
            ->pluck('content', 'subject_id');

        $items = [];
        foreach ($lines as $line) {
            $detail = $line->activity?->detail;
            if ($detail?->start_date_local === null) {
                continue;
            }

            $items[] = new VerdictTimelineItem(
                activityId: (int) $line->activity_id,
                mood: $line->mood,
                moodFace: $this->moodFace($line->mood),
                oneline: (string) $speechByActivity->get($line->activity_id),
                startedAt: $detail->start_date_local,
                distanceKm: DistanceFormatter::km((float) ($detail->distance ?? 0)),
                intensity: $this->intensity($detail->trimp_edwards, $detail->elapsed_time),
            );
        }

        return $items;
    }

    /**
     * @var array<string, string>
     */
    private const array MOOD_FACES = [
        Temari::MOOD_NYALA => '✨',
        Temari::MOOD_ENTENG => '🦘',
        Temari::MOOD_LEMES => '🥵',
        Temari::MOOD_OLENG => '🍳',
        Temari::MOOD_MUMET => '💫',
    ];

    private function moodFace(string $mood): string
    {
        return self::MOOD_FACES[$mood] ?? '🌧️';
    }

    /**
     * Coarse session intensity from TRIMP density (Edwards TRIMP per moving
     * minute). Density separates a short hard effort from a long easy one that
     * both carry high total TRIMP: a pure Z1-Z2 run sits near 1-2, a tempo or
     * interval near 3+. Null when the inputs to compute it are missing.
     */
    private function intensity(?float $trimp, ?int $movingTime): ?string
    {
        if ($trimp === null || $movingTime === null || $movingTime <= 0) {
            return null;
        }

        $density = $trimp / ($movingTime / 60);

        return match (true) {
            $density < 2.0 => 'easy',
            $density <= 2.8 => 'moderate',
            default => 'hard',
        };
    }
}
