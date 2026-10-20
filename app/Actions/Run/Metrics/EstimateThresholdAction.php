<?php

declare(strict_types=1);

namespace App\Actions\Run\Metrics;

use App\Models\User;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\StreamSummary;
use Illuminate\Support\Carbon;

class EstimateThresholdAction
{
    private const int LOOKBACK_DAYS = 60;

    /**
     * Z4+ rather than Z3+: an easy run can log a high Z3+ share, so a Z3+ bar
     * admits easy runs.
     */
    private const float QUALITY_Z4_PLUS_PCT = 30.0;

    /**
     * Longest first, so a genuine hour of threshold work wins when it exists.
     * 20 minutes is the shortest window that still describes sustained effort
     * rather than a surge.
     */
    private const array SUSTAINED_WINDOWS = ['60min', '30min', '20min'];

    public function __construct(private readonly ResolveRecentStreamSummariesAction $recentStreamSummaries)
    {
    }

    /**
     * @return array{pace_sec: float, confidence: 'high'|'medium'|'low', sample_size: int}|null
     */
    public function __invoke(User $user, ?Carbon $asOf = null): ?array
    {
        $paces = collect(($this->recentStreamSummaries)($user, $asOf ?? Carbon::today(), self::LOOKBACK_DAYS))
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
