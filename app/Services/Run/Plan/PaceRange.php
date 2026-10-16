<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PaceBand;

/**
 * The pace window a step is run in, fast end first, mirroring how
 * {@see SessionIntentJudge} grades it: hard steps run from 5% quicker than
 * target (its "too hard" line) to {@see SessionIntentJudge::PACE_TOLERANCE_SEC}
 * slower, marathon-pace blocks sit within that tolerance either side, and easy
 * running spans marathon pace to the slow end of the easy band, since grading
 * never punishes slow easy running.
 */
final readonly class PaceRange
{
    private function __construct(
        public int $fastSecPerKm,
        public int $slowSecPerKm,
    ) {
    }

    public static function forSegment(
        SessionSegment $segment,
        bool $raceEffort,
        ?int $marathonSecPerKm,
        ?int $easySlowEndSecPerKm,
    ): ?self {
        $target = $segment->paceSecPerKm;
        if ($target === null) {
            return null;
        }

        if ($segment->paceLabel === PaceBand::Easy) {
            if ($marathonSecPerKm === null || $easySlowEndSecPerKm === null) {
                return null;
            }

            return new self(min($marathonSecPerKm, $target), max($easySlowEndSecPerKm, $target));
        }

        if ($segment->paceLabel === PaceBand::Marathon && ! $raceEffort) {
            return new self($target - SessionIntentJudge::PACE_TOLERANCE_SEC, $target + SessionIntentJudge::PACE_TOLERANCE_SEC);
        }

        return new self((int) round($target * (1 - SessionIntentJudge::EXCESSIVE_FRACTION)), $target + SessionIntentJudge::PACE_TOLERANCE_SEC);
    }

    /** @return array{fast_sec_per_km: int, slow_sec_per_km: int} */
    public function toArray(): array
    {
        return [
            'fast_sec_per_km' => $this->fastSecPerKm,
            'slow_sec_per_km' => $this->slowSecPerKm,
        ];
    }
}
