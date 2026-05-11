<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use Illuminate\Database\Eloquent\Collection;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Threshold pace from sustained efforts on hard sessions.
 *
 * The openclaw run-tracker derived "threshold pace" from any 20-minute
 * best-effort window, which produced 6:12/km claims for a runner whose
 * actual sustained best-60min was 7:46/km — wildly optimistic targets
 * that depress every compliance score.
 *
 * v1's rule: only count best-30min / best-60min windows from sessions
 * that *actually were hard* (≥30% of time in Z3 or above). Returns null
 * if no qualifying sessions exist in the lookback window.
 *
 * Confidence band reflects how many qualifying sessions backed the answer:
 *   - high:   ≥6 qualifying sessions in the last 60 days
 *   - medium: 3-5
 *   - low:    1-2
 */
class ThresholdEstimator
{
    private const int LOOKBACK_DAYS = 60;

    /** Minimum cumulative Z3+ time-in-zone share that flags a session as hard. */
    private const float HARD_SESSION_Z3_PLUS_PCT = 30.0;

    /**
     * @return array{pace_sec: float, confidence: 'high'|'medium'|'low', sample_size: int}|null
     */
    public function estimate(User $user, ?Carbon $asOf = null): ?array
    {
        $cutoff = ($asOf ?? Carbon::today())->copy()->subDays(self::LOOKBACK_DAYS)->toDateString();

        /** @var Collection<int, ActivityDetail> $details */
        $details = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->where('start_date_local', '>=', $cutoff)
            ->whereNotNull('stream_summary')
            ->get();

        $paces = [];
        foreach ($details as $detail) {
            $summary = is_array($detail->stream_summary) ? $detail->stream_summary : [];
            if (! $this->isHardSession($summary)) {
                continue;
            }
            $bestSustained = $this->bestSustainedPace($summary);
            if ($bestSustained !== null) {
                $paces[] = $bestSustained;
            }
        }

        if ($paces === []) {
            return null;
        }

        // Median of qualifying paces: robust to one freakishly fast or slow session.
        sort($paces);
        $median = $paces[(int) floor((count($paces) - 1) / 2)];

        $confidence = match (true) {
            count($paces) >= 6 => 'high',
            count($paces) >= 3 => 'medium',
            default => 'low',
        };

        return [
            'pace_sec' => round($median, 1),
            'confidence' => $confidence,
            'sample_size' => count($paces),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function isHardSession(array $summary): bool
    {
        $pct = $summary['time_in_zone_pct'] ?? null;
        if (! is_array($pct)) {
            return false;
        }
        $hardShare = (float) ($pct['Z3'] ?? 0)
            + (float) ($pct['Z4'] ?? 0)
            + (float) ($pct['Z5'] ?? 0);

        return $hardShare >= self::HARD_SESSION_Z3_PLUS_PCT;
    }

    /**
     * Prefer best_60min, fall back to best_30min. Anything shorter doesn't
     * count as "sustained" for threshold purposes.
     *
     * @param  array<string, mixed>  $summary
     */
    private function bestSustainedPace(array $summary): ?float
    {
        foreach (['best_60min_pace', 'best_30min_pace'] as $key) {
            $label = $summary[$key] ?? null;
            if (! is_string($label)) {
                continue;
            }
            $secs = PaceFormatter::parse($label);
            if ($secs !== null) {
                return $secs;
            }
        }

        return null;
    }
}
