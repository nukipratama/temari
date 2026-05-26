<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use Illuminate\Database\Eloquent\Collection;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Support\Carbon;

class ThresholdEstimator
{
    private const int LOOKBACK_DAYS = 60;

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
            $summary = $detail->streamSummary();
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
