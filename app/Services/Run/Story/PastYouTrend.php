<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Enums\TrendVerdict;

/**
 * The home-screen answer to "am I getting better?": one verdict plus the
 * matched pairs it was computed from, so the claim is always shown with its
 * evidence. Every supporting reading is nullable because it comes from the
 * detail pipeline, which lazy hydration may not have reached yet.
 */
final readonly class PastYouTrend
{
    /**
     * @param  list<PastYouComparison>  $comparisons
     */
    public function __construct(
        public TrendVerdict $verdict,
        public array $comparisons,
        public int $windowDays,
        public ?float $meanPaceDeltaSec = null,
        public ?float $meanHrDeltaBpm = null,
        public ?float $fitnessDeltaCtl = null,
        public ?string $paceConsistencyNow = null,
        public ?string $paceConsistencyThen = null,
        public ?string $relativeEffortBand = null,
    ) {
    }

    /**
     * @param  list<PastYouComparison>  $comparisons
     */
    public static function notEnoughHistory(int $windowDays, array $comparisons = []): self
    {
        return new self(TrendVerdict::NotEnoughHistory, $comparisons, $windowDays);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict->value,
            'window_days' => $this->windowDays,
            'comparison_count' => count($this->comparisons),
            'comparisons' => array_map(
                static fn (PastYouComparison $comparison): array => $comparison->toArray(),
                $this->comparisons,
            ),
            'mean_pace_delta_sec' => $this->meanPaceDeltaSec,
            'mean_hr_delta_bpm' => $this->meanHrDeltaBpm,
            'fitness_delta_ctl' => $this->fitnessDeltaCtl,
            'pace_consistency_now' => $this->paceConsistencyNow,
            'pace_consistency_then' => $this->paceConsistencyThen,
            'relative_effort_band' => $this->relativeEffortBand,
        ];
    }
}
