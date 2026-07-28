<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\StoryLine;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Carbon;

/**
 * How the runner's moods have been distributed, and whether that distribution
 * is shifting: the same window split into its recent and earlier halves.
 */
final class PersonaMixTool extends UserTool
{
    private const int LOOKBACK_WEEKS = 12;

    public function name(): string
    {
        return 'get_persona_mix';
    }

    public function description(): string
    {
        return 'Sebaran mood pengguna selama 12 minggu terakhir, plus paruh terbaru dan paruh '
            .'sebelumnya secara terpisah supaya kelihatan kalau ada pergeseran, dan form_status '
            .'terakhirnya. Daftar kosong berarti belum ada cukup lari buat dibaca.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $windowStart = $this->asOf->copy()->subWeeks(self::LOOKBACK_WEEKS);
        $halfway = $this->asOf->copy()->subWeeks(intdiv(self::LOOKBACK_WEEKS, 2));

        // The full window is the two halves put back together, so it is folded
        // in PHP rather than asked for as a third overlapping group-by.
        $recent = $this->moodMixBetween($halfway, null);
        $earlier = $this->moodMixBetween($windowStart, $halfway);
        $mix = self::merge($recent, $earlier);

        return [
            'lookback_weeks' => self::LOOKBACK_WEEKS,
            'total_runs' => array_sum(array_map(static fn (array $row): int => $row['count'], $mix)),
            'persona_mix' => $mix,
            'persona_mix_recent' => $recent,
            'persona_mix_earlier' => $earlier,
            'form_status' => WeeklySnapshot::latestFormStatus($this->user->id),
        ];
    }

    /**
     * Two half-window mixes summed back into one, with percentages recomputed
     * against the combined total and the same count-descending order.
     *
     * @param  list<array{mood: string, count: int, percent: float}>  $recent
     * @param  list<array{mood: string, count: int, percent: float}>  $earlier
     * @return list<array{mood: string, count: int, percent: float}>
     */
    private static function merge(array $recent, array $earlier): array
    {
        $counts = [];
        foreach ([...$recent, ...$earlier] as $row) {
            $counts[$row['mood']] = ($counts[$row['mood']] ?? 0) + $row['count'];
        }

        $total = array_sum($counts);
        if ($total === 0) {
            return [];
        }

        $mix = [];
        foreach ($counts as $mood => $count) {
            $mix[] = [
                'mood' => $mood,
                'count' => $count,
                'percent' => round(($count / $total) * 100, 1),
            ];
        }

        usort($mix, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $mix;
    }

    /** @return list<array{mood: string, count: int, percent: float}> */
    private function moodMixBetween(Carbon $from, ?Carbon $to): array
    {
        $rows = StoryLine::query()
            ->where('user_id', $this->user->id)
            ->whereNotNull('activity_id')
            ->where('created_at', '>=', $from)
            ->when($to !== null, fn ($query) => $query->where('created_at', '<', $to))
            ->selectRaw('mood, COUNT(*) as c')
            ->groupBy('mood')
            ->pluck('c', 'mood');

        $total = (int) $rows->sum();
        if ($total === 0) {
            return [];
        }

        $mix = [];
        foreach ($rows as $mood => $count) {
            $count = (int) $count;
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
