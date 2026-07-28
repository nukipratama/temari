<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\WeeklySnapshot;
use App\Services\Run\Story\MoodMix;

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
        $recent = MoodMix::between($this->user->id, $halfway);
        $earlier = MoodMix::between($this->user->id, $windowStart, $halfway);
        $mix = MoodMix::merge($recent, $earlier);

        return [
            'lookback_weeks' => self::LOOKBACK_WEEKS,
            'total_runs' => array_sum(array_map(static fn (array $row): int => $row['count'], $mix)),
            'persona_mix' => $mix,
            'persona_mix_recent' => $recent,
            'persona_mix_earlier' => $earlier,
            'form_status' => WeeklySnapshot::latestFormStatus($this->user->id),
        ];
    }
}
