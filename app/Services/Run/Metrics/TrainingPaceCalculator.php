<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * One guide pace per training zone, each read off the same VDOT race-time
 * model {@see VdotEstimator} fits races with: marathon is the athlete's
 * marathon race-equivalent pace, threshold the pace they could race for an
 * hour, interval the pace they could race for about eleven minutes. Easy sits
 * at the midpoint of the VDOT calculator's E band on the VO2 curve, which is
 * a fixed VO2 fraction from VDOT 40 and rises toward marathon pace below it.
 */
class TrainingPaceCalculator
{
    private const float MARATHON_METERS = 42_195.0;

    private const float THRESHOLD_EFFORT_MINUTES = 60.0;

    private const float INTERVAL_EFFORT_MINUTES = 11.0;

    private const float EASY_MID_FRACTION = 0.657;

    private const float EASY_MID_FRACTION_AT_LOW_VDOT = 0.728;

    private const float EASY_FLAT_FROM_VDOT = 40.0;

    private const float EASY_LOW_VDOT = 30.0;

    private const float EASY_HALF_BAND_FRACTION = 0.041;

    public function __construct(private readonly VdotEstimator $vdotEstimator)
    {
    }

    /**
     * Convenience wrapper around {@see self::fromVdot()} for callers holding a
     * {@see VdotEstimator::estimate()} result directly, which is null whenever
     * there is not yet enough PR history to estimate a VDOT.
     *
     * @param  array{vdot: float, quality_vdot?: float, ...}|null  $vdotResult
     * @return array{easy: int, marathon: int, threshold: int, interval: int}|null seconds per kilometre
     */
    public function fromVdotResult(?array $vdotResult): ?array
    {
        if ($vdotResult === null) {
            return null;
        }

        return $this->fromVdot($vdotResult['vdot'], $vdotResult['quality_vdot'] ?? null);
    }

    /**
     * @return array{easy: int, marathon: int, threshold: int, interval: int} seconds per kilometre
     */
    public function fromVdot(float $vdot, ?float $qualityVdot = null): array
    {
        $quality = $qualityVdot ?? $vdot;
        $marathonSec = $this->vdotEstimator->raceTimeForVdot($vdot, self::MARATHON_METERS) ?? 0.0;

        return [
            'easy' => (int) round($this->paceFromVo2Fraction($vdot, self::easyMidFraction($vdot))),
            'marathon' => (int) round($marathonSec / (self::MARATHON_METERS / 1000)),
            'threshold' => (int) round($this->paceFromVo2Fraction($quality, VdotEstimator::sustainableVo2Fraction(self::THRESHOLD_EFFORT_MINUTES))),
            'interval' => (int) round($this->paceFromVo2Fraction($quality, VdotEstimator::sustainableVo2Fraction(self::INTERVAL_EFFORT_MINUTES))),
        ];
    }

    /**
     * The slow end of the easy band on its own, for the one caller that wants
     * it rather than the midpoint {@see self::fromVdot()} guides with (see
     * `ReadinessClamp::paceEaseApplies()`).
     */
    public function easySlowEndSecPerKm(float $vdot): int
    {
        return (int) round($this->paceFromVo2Fraction($vdot, self::easyMidFraction($vdot) - self::EASY_HALF_BAND_FRACTION));
    }

    /** @param  array{vdot: float, quality_vdot?: float, ...}|null  $vdotResult */
    public function easySlowEndFromVdotResult(?array $vdotResult): ?int
    {
        return $vdotResult === null ? null : $this->easySlowEndSecPerKm($vdotResult['vdot']);
    }

    private static function easyMidFraction(float $vdot): float
    {
        $belowFlat = self::EASY_FLAT_FROM_VDOT - min(self::EASY_FLAT_FROM_VDOT, max(self::EASY_LOW_VDOT, $vdot));

        return self::EASY_MID_FRACTION
            + $belowFlat / (self::EASY_FLAT_FROM_VDOT - self::EASY_LOW_VDOT) * (self::EASY_MID_FRACTION_AT_LOW_VDOT - self::EASY_MID_FRACTION);
    }

    /**
     * Seconds per kilometre for the velocity that produces VO2 = $fraction * $vdot.
     */
    private function paceFromVo2Fraction(float $vdot, float $fraction): float
    {
        $vo2 = $fraction * $vdot;

        $a = VdotEstimator::VO2_COEFFICIENT_A;
        $b = VdotEstimator::VO2_COEFFICIENT_B;
        $c = VdotEstimator::VO2_COEFFICIENT_C - $vo2;

        $velocity = (-$b + sqrt($b ** 2 - 4 * $a * $c)) / (2 * $a); // m/min

        return 60_000 / $velocity;
    }
}
