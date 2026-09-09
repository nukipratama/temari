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

    /**
     * Z4+ rather than Z3+. Measured against a real athlete's 60 days, a 30% Z3+
     * bar admitted 30 of 38 runs, including easy ones: an easy 10 km at 7:31/km
     * logged 84.3% Z3+. The same 30% bar on Z4+ admits 8, and every one of them
     * ran 6:26/km or faster while everything rejected ran 6:43/km or slower.
     */
    private const float QUALITY_Z4_PLUS_PCT = 30.0;

    /**
     * Longest first, so a genuine hour of threshold work wins when it exists.
     * 20 minutes is the shortest window that still describes sustained effort
     * rather than a surge, and without it a 28-minute time trial contributes
     * nothing while slower, longer runs set the estimate.
     */
    private const array SUSTAINED_WINDOWS = ['60min', '30min', '20min'];

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
            ->get(['id', 'stream_summary']);

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
        return $summary->thresholdZoneShare() >= self::QUALITY_Z4_PLUS_PCT;
    }

    private function bestSustainedPace(StreamSummary $summary): ?float
    {
        foreach (self::SUSTAINED_WINDOWS as $window) {
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
