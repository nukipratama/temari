<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Enums\TrendDirection;

/**
 * One matched pair of the runner's own runs and the deltas between them.
 * `paceDeltaSec` is positive when the recent run is faster; `hrDeltaBpm` is
 * negative when the recent run's average heart rate is lower.
 */
final readonly class PastYouComparison
{
    /**
     * How much faster or slower a matched pair has to be before the difference
     * counts as anything. Two runs of the same distance in the same pace band
     * routinely land a few seconds apart on nothing but wind and traffic
     * lights, so a smaller gap than this reads as noise, in either direction.
     */
    public const float PACE_SIGNAL_SEC = 5.0;

    /** Same idea for average heart rate: day-to-day drift below this is not a reading. */
    public const float HR_SIGNAL_BPM = 3.0;

    public function __construct(
        public ComparableRun $current,
        public ComparableRun $past,
        public float $similarity,
        public float $paceDeltaSec,
        public ?float $hrDeltaBpm,
        public int $daysApart,
    ) {
    }

    public static function between(ComparableRun $current, ComparableRun $past, float $similarity): self
    {
        $hrDelta = $current->averageHeartrate === null || $past->averageHeartrate === null
            ? null
            : round($current->averageHeartrate - $past->averageHeartrate, 1);

        return new self(
            current: $current,
            past: $past,
            similarity: round($similarity, 3),
            paceDeltaSec: round($past->paceSecPerKm - $current->paceSecPerKm, 1),
            hrDeltaBpm: $hrDelta,
            daysApart: $past->daysBefore($current),
        );
    }

    /**
     * Pace leads, because it is the reading the runner recognises. Heart rate
     * only decides the call when pace came back inside the noise band: same
     * pace at a lower heart rate is a real gain, and same pace at a higher one
     * is a real loss.
     */
    public function direction(): TrendDirection
    {
        if ($this->paceDeltaSec >= self::PACE_SIGNAL_SEC) {
            return TrendDirection::Better;
        }

        if ($this->paceDeltaSec <= -self::PACE_SIGNAL_SEC) {
            return TrendDirection::Worse;
        }

        if ($this->hrDeltaBpm !== null && $this->hrDeltaBpm <= -self::HR_SIGNAL_BPM) {
            return TrendDirection::Better;
        }

        if ($this->hrDeltaBpm !== null && $this->hrDeltaBpm >= self::HR_SIGNAL_BPM) {
            return TrendDirection::Worse;
        }

        return TrendDirection::Flat;
    }

    /**
     * @return array{direction: string, days_apart: int, similarity: float, pace_delta_sec: float, hr_delta_bpm: float|null, current: array<string, mixed>, past: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction()->value,
            'days_apart' => $this->daysApart,
            'similarity' => $this->similarity,
            'pace_delta_sec' => $this->paceDeltaSec,
            'hr_delta_bpm' => $this->hrDeltaBpm,
            'current' => $this->current->toArray(),
            'past' => $this->past->toArray(),
        ];
    }
}
