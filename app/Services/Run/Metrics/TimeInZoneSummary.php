<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Where the athlete's training time actually went, Z1 through Z5, across a
 * trailing window. Per-run zone minutes are already written by
 * {@see \App\Services\Run\Ingest\StreamAnalysis} onto
 * {@see \App\Models\ActivityDetail::$stream_summary}; this sums them and
 * normalises to percentages, so the answer reflects how long was spent in each
 * band rather than how many runs touched it.
 */
final readonly class TimeInZoneSummary
{
    public const int WINDOW_WEEKS = 12;

    /**
     * Percent of recorded zone time per zone, keyed `Z1`..`Z5` and summing to
     * 100. Empty when no run in the window recorded heart rate, which is the
     * signal to draw nothing at all rather than an empty bar.
     *
     * @return array<string, float>
     */
    public function forUser(User $user, ?Carbon $today = null): array
    {
        $since = ($today ?? Carbon::today())->copy()->subWeeks(self::WINDOW_WEEKS)->startOfDay();

        $minutes = array_fill_keys(HeartRateZones::KEYS, 0.0);
        $total = 0.0;

        ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->where('start_date_local', '>=', $since)
            ->whereNotNull('stream_summary')
            ->select(['id', 'stream_summary'])
            ->each(function (ActivityDetail $detail) use (&$minutes, &$total): void {
                $perZone = StreamSummary::fromArray($detail->stream_summary)->zoneMinutes() ?? [];
                foreach (HeartRateZones::KEYS as $zone) {
                    $value = (float) ($perZone[$zone] ?? 0);
                    $minutes[$zone] += $value;
                    $total += $value;
                }
            });

        if ($total <= 0.0) {
            return [];
        }

        return array_map(
            static fn (float $zoneMinutes): float => round($zoneMinutes / $total * 100, 1),
            $minutes,
        );
    }
}
