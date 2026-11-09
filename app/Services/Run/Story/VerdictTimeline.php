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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Override;
use App\Enums\Mood;

class VerdictTimeline implements VerdictNarrator
{
    public const DEFAULT_LIMIT = 8;

    /**
     * The user's most recent narrated runs, newest first.
     *
     * Every filter is in the query so the LIMIT can be too.
     *
     * @return list<VerdictTimelineItem>
     */
    #[Override]
    public function recent(User $user, int $limit = self::DEFAULT_LIMIT): array
    {
        return $this->items(
            $this->lines($user)
                ->whereExists(fn (QueryBuilder $query) => $query
                    ->from('ai_analyses')
                    ->whereColumn('ai_analyses.subject_id', 'story_lines.activity_id')
                    ->where('ai_analyses.subject_type', Activity::class)
                    ->where('ai_analyses.analysis_type', AnalysisType::PostRunSpeech->value)
                    ->where('ai_analyses.status', AnalysisStatus::Done->value)
                    ->whereNotNull('ai_analyses.content')
                    ->where('ai_analyses.content', '!=', ''))
                ->orderByDesc('activity_details.start_date_local')
                ->limit($limit),
        );
    }

    /**
     * Every run with a post-run story line between the two days inclusive, oldest first. A run
     * whose post-run speech is not Done yet carries an empty oneline.
     *
     * @return list<VerdictTimelineItem>
     */
    #[Override]
    public function between(User $user, Carbon $from, Carbon $to): array
    {
        return $this->items(
            $this->lines($user)
                ->whereBetween('activity_details.start_date_local', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->orderBy('activity_details.start_date_local'),
        );
    }

    /**
     * {@see \App\Models\Scopes\AnalyzedScope} does not reach a join from `story_lines`, so
     * `analyzed_at` is asserted here directly.
     *
     * @return Builder<StoryLine>
     */
    private function lines(User $user): Builder
    {
        return Activity::analyzedJoinConstraint(
            StoryLine::query()
                ->select('story_lines.*')
                ->join('activities', 'activities.id', '=', 'story_lines.activity_id')
                ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id'),
        )
            ->where('story_lines.user_id', $user->id)
            ->where('story_lines.kind', StoryLine::KIND_POST_RUN)
            ->whereNotNull('activity_details.start_date_local')
            // Column-limited so the run's stream_summary blob is never hydrated;
            // only these four fields are read below.
            ->with(['activity.detail' => fn ($query) => $query->select(
                'id',
                'activity_id',
                'start_date_local',
                'distance',
                'trimp_edwards',
                'elapsed_time',
            )]);
    }

    /**
     * @param  Builder<StoryLine>  $query
     * @return list<VerdictTimelineItem>
     */
    private function items(Builder $query): array
    {
        /** @var Collection<int, StoryLine> $lines */
        $lines = $query->get();

        if ($lines->isEmpty()) {
            return [];
        }

        $speechByActivity = Analysis::query()
            ->where('subject_type', Activity::class)
            ->where('analysis_type', AnalysisType::PostRunSpeech)
            ->where('status', AnalysisStatus::Done)
            ->whereIn('subject_id', $lines->pluck('activity_id')->all())
            ->get(['subject_id', 'content'])
            ->keyBy('subject_id');

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
                oneline: $speechByActivity->get($line->activity_id)?->narration()->body ?? '',
                startedAt: $detail->start_date_local,
                distanceKm: DistanceFormatter::km((float) ($detail->distance ?? 0)),
                intensity: $this->intensity($detail->trimp_edwards, $detail->elapsed_time),
            );
        }

        return $items;
    }

    private function moodFace(Mood $mood): string
    {
        return match ($mood) {
            Mood::Blazing => '✨',
            Mood::Easy => '🦘',
            Mood::Gassed => '🥵',
            Mood::Wobbly => '🍳',
            Mood::Overloaded => '💫',
            Mood::Chill => '🌧️',
        };
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
