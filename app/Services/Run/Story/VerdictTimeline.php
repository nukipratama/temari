<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class VerdictTimeline implements VerdictNarrator
{
    public const DEFAULT_LIMIT = 8;

    /**
     * @return list<VerdictTimelineItem>
     */
    #[Override]
    public function recent(User $user, int $limit = self::DEFAULT_LIMIT): array
    {
        /** @var Collection<int, StoryLine> $lines */
        $lines = StoryLine::query()
            ->with('activity.detail')
            ->where('user_id', $user->id)
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->whereNotNull('activity_id')
            ->get();

        if ($lines->isEmpty()) {
            return [];
        }

        $activityIds = $lines->pluck('activity_id')->all();
        $speechByActivity = Analysis::query()
            ->where('subject_type', Activity::class)
            ->where('analysis_type', AnalysisType::PostRunSpeech)
            ->where('status', AnalysisStatus::Done)
            ->whereIn('subject_id', $activityIds)
            ->pluck('content', 'subject_id');

        $items = [];
        foreach ($lines as $line) {
            $activity = $line->activity;
            $detail = $activity?->detail;
            if ($detail === null || $detail->start_date_local === null) {
                continue;
            }

            $speech = $speechByActivity->get($line->activity_id);
            if ($speech === null || $speech === '') {
                continue;
            }

            $items[] = new VerdictTimelineItem(
                activityId: (int) $line->activity_id,
                mood: $line->mood,
                moodFace: $this->moodFace($line->mood),
                oneline: $speech,
                startedAt: $detail->start_date_local,
                distanceKm: round((float) ($detail->distance ?? 0) / 1000, 1),
            );
        }

        usort(
            $items,
            fn (VerdictTimelineItem $a, VerdictTimelineItem $b): int => $b->startedAt->timestamp <=> $a->startedAt->timestamp,
        );

        return array_slice($items, 0, $limit);
    }

    private function moodFace(string $mood): string
    {
        return match ($mood) {
            Temari::MOOD_GLOW => '✨',
            Temari::MOOD_BOUNCY => '🦘',
            Temari::MOOD_WOBBLE => '🥵',
            Temari::MOOD_SQUISHED => '🍳',
            Temari::MOOD_SPINNING => '💫',
            default => '🌧️',
        };
    }
}
