<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use Illuminate\Support\Carbon;

/**
 * The days after a race with known load that carry no quality work, and for
 * a marathon-class distance the first full week after it at the deload
 * multiplier. Anchored to the race date, so whichever arc the plan is on
 * when it regenerates applies the same window.
 */
final readonly class PostRaceRecovery
{
    public const int MARATHON_CLASS_M = 30_000;

    public const int HALF_CLASS_M = 15_000;

    public function __construct(
        public Carbon $raceDate,
        public Carbon $noQualityThrough,
        public ?Carbon $deloadWeekStart,
    ) {
    }

    public static function after(Carbon $raceDate, float $distanceRunM): self
    {
        $raceDay = $raceDate->copy()->startOfDay();
        [$noQualityDays, $deloads] = match (true) {
            $distanceRunM >= self::MARATHON_CLASS_M => [14, true],
            $distanceRunM > self::HALF_CLASS_M => [7, false],
            default => [3, false],
        };

        return new self(
            $raceDay,
            $raceDay->copy()->addDays($noQualityDays),
            $deloads ? $raceDay->copy()->startOfWeek(Carbon::MONDAY)->addWeek() : null,
        );
    }

    public function excludesQualityOn(string $date): bool
    {
        return $date > $this->raceDate->toDateString() && $date <= $this->noQualityThrough->toDateString();
    }

    public function deloadsWeek(Carbon $weekStart): bool
    {
        return $this->deloadWeekStart?->isSameDay($weekStart) ?? false;
    }
}
