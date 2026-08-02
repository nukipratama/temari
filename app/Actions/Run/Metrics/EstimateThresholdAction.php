<?php

declare(strict_types=1);

namespace App\Actions\Run\Metrics;

use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\StreamSummary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class EstimateThresholdAction
{
    private const int LOOKBACK_DAYS = 60;

    private const float HARD_SESSION_Z3_PLUS_PCT = 30.0;

    /**
     * @return array{pace_sec: float, confidence: 'high'|'medium'|'low', sample_size: int}|null
     */
    public function __invoke(User $user, ?Carbon $asOf = null): ?array
    {
        $cutoff = ($asOf ?? Carbon::today())->copy()->subDays(self::LOOKBACK_DAYS)->toDateString();

        /** @var Collection<int, ActivityDetail> $details */
        $details = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->where('start_date_local', '>=', $cutoff)
            ->whereNotNull('stream_summary')
            ->get();

        $paces = $details
            ->map(fn (ActivityDetail $detail): StreamSummary => StreamSummary::fromArray($detail->streamSummary()))
            ->filter(fn (StreamSummary $summary): bool => $this->isHardSession($summary))
            ->map(fn (StreamSummary $summary): ?float => $this->bestSustainedPace($summary))
            ->filter(fn (?float $pace): bool => $pace !== null)
            ->values()
            ->all();

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

    private function isHardSession(StreamSummary $summary): bool
    {
        return $summary->hardZoneShare() >= self::HARD_SESSION_Z3_PLUS_PCT;
    }

    private function bestSustainedPace(StreamSummary $summary): ?float
    {
        foreach (['60min', '30min'] as $window) {
            $label = $summary->bestPace($window);
            if ($label === null) {
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
