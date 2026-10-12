<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PaceBand;
use App\Enums\RaceAmbitionState;
use App\Enums\RaceSupport;

/**
 * The last fifth of a 5K-to-half goal's long run at race-specific pace.
 * See `docs/decisions/a-fast-finish-long-run-for-5k-to-half-goals.md`.
 */
final readonly class FastFinishLongRun
{
    public const string KIND = 'fast_finish';

    public const float SHARE = 0.2;

    public const int MIN_WEEKS_APART = 2;

    public const string PACE_GOAL = 'goal';

    public const string PACE_STEPPING_STONE = 'stepping_stone';

    public const string PACE_SUPPORTED = 'supported';

    public function __construct(
        public string $raceKind,
        public int $distanceM,
        public int $paceSecPerKm,
        public string $paceKind,
        public string $paceSource,
    ) {
    }

    /**
     * The fast finish a long run of this week would carry: at the week's
     * goal-pace work pace, otherwise at the supported race pace, or the
     * supported 10K pace for a 5K goal.
     */
    public static function forWeek(PlanInputs $inputs, ?GoalPaceWork $goalPace): ?self
    {
        $distanceM = $inputs->raceDistanceM;
        if ($inputs->raceDate === null || $distanceM === null
            || ! RaceSupport::forDistance($distanceM)->dedicatedPreparation()
            || RaceSupport::isMarathonClass($distanceM)) {
            return null;
        }
        $raceKind = GoalPaceWork::kindFor($distanceM);
        if ($goalPace !== null) {
            $source = GoalPaceWork::isSteppingStone($goalPace->context()) ? self::PACE_STEPPING_STONE : self::PACE_GOAL;

            return new self($raceKind, (int) round($distanceM), $goalPace->goalPaceSecPerKm, $raceKind, $source);
        }
        [$supportedSec, $paceDistanceM] = $raceKind === GoalPaceWork::KIND_5K
            ? [$inputs->supportedTenKTimeSec, 10_000.0]
            : [$inputs->raceSupportedTimeSec, $distanceM];
        if ($supportedSec === null || $inputs->raceAmbitionState === RaceAmbitionState::Unknown) {
            return null;
        }

        return new self($raceKind, (int) round($distanceM), (int) round($supportedSec / ($paceDistanceM / 1000)), GoalPaceWork::kindFor($paceDistanceM), self::PACE_SUPPORTED);
    }

    public function prescription(float $longKm): IntensityPrescription
    {
        $minutes = (int) round($longKm * self::SHARE * $this->paceSecPerKm / 60);

        return new IntensityPrescription($minutes, $this->paceBand(), $this->paceSecPerKm, 'fast finish at race pace', $this->context());
    }

    public function paceBand(): PaceBand
    {
        return $this->paceKind === GoalPaceWork::KIND_5K ? PaceBand::Interval : PaceBand::Threshold;
    }

    /** @return array{kind: string, race_kind: string, distance_m: int, pace_kind: string, pace_source: string} */
    public function context(): array
    {
        return ['kind' => self::KIND, 'race_kind' => $this->raceKind, 'distance_m' => $this->distanceM, 'pace_kind' => $this->paceKind, 'pace_source' => $this->paceSource];
    }

    /** @param  array<string, int|float|string>|null  $context */
    public static function isFastFinish(?array $context): bool
    {
        return ($context['kind'] ?? null) === self::KIND;
    }
}
