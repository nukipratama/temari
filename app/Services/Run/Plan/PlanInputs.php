<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\AdaptationReason;
use Illuminate\Support\Carbon;

/**
 * Everything {@see Periodizer::rowsFor()} needs to compute a plan, read once
 * by {@see PlanInputsGatherer} so the computation itself never touches the
 * database. Assembling one by hand is how the arc's ramp, deload, taper and
 * peak behaviour is asserted without seeding an athlete.
 */
final readonly class PlanInputs
{
    /**
     * @param  list<int>|null  $runDays  ISO weekdays the athlete chose, if any
     * @param  array{reason: AdaptationReason, deload: bool, quality_delta: int, adherence_pct: int}  $adaptation
     * @param  array<string, true>  $pinnedDates  Y-m-d the athlete fixed, never overwritten
     * @param  array<string, true>  $settledDates  Y-m-d already carrying a verdict
     */
    public function __construct(
        public int $userId,
        public Carbon $today,
        public Carbon $seasonStart,
        public Carbon $seasonEnd,
        public bool $seasonOpensWithRecovery,
        public ?Carbon $raceDate,
        public ?float $raceDistanceM,
        public int $sessionsPerWeek,
        public ?array $runDays,
        public ?int $longRunDay,
        public array $adaptation,
        public array $pinnedDates,
        public array $settledDates,
        public ?float $projectedRaceSeconds,
    ) {
    }

    public function currentWeekStart(): Carbon
    {
        return $this->today->copy()->startOfWeek(Carbon::MONDAY);
    }

    /** The last date the horizon materializes, and the far edge of the stale-row sweep. */
    public function horizonEnd(): Carbon
    {
        return $this->currentWeekStart()->addWeeks(Periodizer::HORIZON_WEEKS - 1)->addDays(6);
    }

    /**
     * The arc is counted from the season's own start, not from today — see
     * `docs/decisions/the-arc-is-anchored-once.md`.
     */
    public function arcStart(): Carbon
    {
        return $this->seasonStart->copy()->startOfWeek(Carbon::MONDAY);
    }

    public function isSelfScaled(): bool
    {
        return $this->raceDate === null;
    }
}
