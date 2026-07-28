<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\StoryLine;
use Illuminate\Support\Carbon;

/**
 * How a runner's moods were distributed over a window, newest concern first:
 * each mood with its run count and share, ordered by count descending.
 *
 * Read by the persona-mix and month-totals tools and by the Aku page's persona
 * bar. The three had grown their own copies of the same group-by; they agreed
 * with each other, but there is no reason for three.
 *
 * Windows are half-open — `[from, to)` — so adjacent windows tile without
 * double-counting a run on the seam. Callers holding an inclusive end (a
 * `endOfMonth()`, say) must pass the start of the next period instead.
 */
final class MoodMix
{
    /**
     * @param  Carbon|null  $to  Open-ended when null, so runs logged moments ago still count.
     * @return list<array{mood: string, count: int, percent: float}>
     */
    public static function between(int $userId, Carbon $from, ?Carbon $to = null): array
    {
        $counts = StoryLine::query()
            ->where('user_id', $userId)
            ->whereNotNull('activity_id')
            ->where('created_at', '>=', $from)
            ->when($to !== null, fn ($query) => $query->where('created_at', '<', $to))
            ->selectRaw('mood, COUNT(*) as c')
            ->groupBy('mood')
            ->pluck('c', 'mood')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return self::shape($counts);
    }

    /**
     * Two adjacent mixes folded back into one, with shares recomputed against
     * the combined total. Lets a caller that already has both halves skip a
     * third query for the window they add up to.
     *
     * @param  list<array{mood: string, count: int, percent: float}>  ...$mixes
     * @return list<array{mood: string, count: int, percent: float}>
     */
    public static function merge(array ...$mixes): array
    {
        $counts = [];
        foreach ($mixes as $mix) {
            foreach ($mix as $row) {
                $counts[$row['mood']] = ($counts[$row['mood']] ?? 0) + $row['count'];
            }
        }

        return self::shape($counts);
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{mood: string, count: int, percent: float}>
     */
    private static function shape(array $counts): array
    {
        $total = array_sum($counts);
        if ($total === 0) {
            return [];
        }

        $mix = [];
        foreach ($counts as $mood => $count) {
            $mix[] = [
                'mood' => (string) $mood,
                'count' => $count,
                'percent' => round(($count / $total) * 100, 1),
            ];
        }

        usort($mix, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $mix;
    }
}
