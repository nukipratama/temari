<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Actions\Run\Metrics\ResolveRecentStreamSummariesAction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Where the athlete's training time actually went, Z1 through Z5, across a
 * trailing window. Per-run zone minutes are already written by
 * {@see \App\Services\Run\Ingest\StreamAnalysis} onto the `stream_summary`
 * blob of {@see \App\Models\ActivityDetail}; this sums them and normalises to
 * percentages, so the answer reflects how long was spent in each band rather
 * than how many runs touched it.
 */
final readonly class TimeInZoneSummary
{
    public const int WINDOW_WEEKS = 12;

    public function __construct(private ResolveRecentStreamSummariesAction $recentStreamSummaries)
    {
    }

    /**
     * Percent of recorded zone time per zone, keyed `Z1`..`Z5` and summing to
     * 100. Empty when no run in the window recorded heart rate, which is the
     * signal to draw nothing at all rather than an empty bar.
     *
     * @return array<string, float>
     */
    public function forUser(User $user, ?Carbon $today = null): array
    {
        $minutes = array_fill_keys(HeartRateZones::KEYS, 0.0);
        $total = 0.0;

        foreach (($this->recentStreamSummaries)($user, $today ?? Carbon::today(), self::WINDOW_WEEKS * 7) as $summary) {
            $perZone = $summary->zoneMinutes() ?? [];
            foreach (HeartRateZones::KEYS as $zone) {
                $value = (float) ($perZone[$zone] ?? 0);
                $minutes[$zone] += $value;
                $total += $value;
            }
        }

        if ($total <= 0.0) {
            return [];
        }

        return array_map(
            static fn (float $zoneMinutes): float => round($zoneMinutes / $total * 100, 1),
            $minutes,
        );
    }
}
