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
            if (blank($speech)) {
                continue;
            }

            $items[] = new VerdictTimelineItem(
                activityId: (int) $line->activity_id,
                mood: $line->mood,
                moodFace: $this->moodFace($line->mood),
                oneline: $speech,
                startedAt: $detail->start_date_local,
                distanceKm: round((float) ($detail->distance ?? 0) / 1000, 1),
                intensity: $this->intensity($detail->trimp_edwards, $detail->moving_time),
            );
        }

        usort(
            $items,
            fn (VerdictTimelineItem $a, VerdictTimelineItem $b): int => $b->startedAt->timestamp <=> $a->startedAt->timestamp,
        );

        return array_slice($items, 0, $limit);
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
