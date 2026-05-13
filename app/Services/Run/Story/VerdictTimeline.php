<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use Illuminate\Database\Eloquent\Collection;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\Story\Contracts\VerdictNarrator;

/**
 * Pulls the latest N post-run StoryLines for a user and reshapes them
 * into render-ready `VerdictTimelineItem`s for the dashboard "Kata Temari"
 * strip. No write side — strip is a pure view onto data the ingest
 * pipeline already populates via `Temari::postRunLine()`.
 */
class VerdictTimeline implements VerdictNarrator
{
    public const DEFAULT_LIMIT = 8;

    /**
     * @return list<VerdictTimelineItem>
     */
    public function recent(User $user, int $limit = self::DEFAULT_LIMIT): array
    {
        /** @var Collection<int, StoryLine> $lines */
        $lines = StoryLine::query()
            ->with('activity.detail')
            ->where('user_id', $user->id)
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->whereNotNull('activity_id')
            ->get();

        $items = [];
        foreach ($lines as $line) {
            $activity = $line->activity;
            $detail = $activity?->detail;
            if ($detail === null || $detail->start_date_local === null) {
                continue;
            }

            $items[] = new VerdictTimelineItem(
                activityId: (int) $line->activity_id,
                mood: $line->mood,
                moodFace: $this->moodFace($line->mood),
                oneline: $line->speech,
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
