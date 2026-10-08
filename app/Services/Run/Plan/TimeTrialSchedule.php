<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanPhase;
use Illuminate\Support\Carbon;

/**
 * Which weeks of a season hold a time trial. A race season counts back from
 * race week, a season with no race counts forward from its own start; a trial
 * due in a scheduled Deload week falls due the week before it, a trial that
 * cannot be placed in its week moves to the next week of its cycle, and a
 * skipped trial is offered once more the week after. See
 * `docs/decisions/a-time-trial-every-six-weeks.md`.
 */
final class TimeTrialSchedule
{
    public const int CADENCE_WEEKS = 6;

    public const int LAST_TRIAL_WEEKS_BEFORE_RACE = 4;

    public const int TRIAL_FREE_WEEKS_BEFORE_RACE = 3;

    public const int SELF_SCALED_FIRST_WEEK = 3;

    public const int RECENT_EVIDENCE_WEEKS = 4;

    /** @var list<string> */
    private readonly array $dueWeeks;

    /** @var array<string, true> */
    private array $trialWeeks = [];

    /** @param  list<string>  $deloadWeeks  the Mondays of the arc's Deload weeks */
    public function __construct(private readonly PlanInputs $inputs, array $deloadWeeks = [])
    {
        $this->dueWeeks = array_map(
            static fn (string $due): string => in_array($due, $deloadWeeks, true) ? Carbon::parse($due)->subWeek()->toDateString() : $due,
            self::dueWeeks($inputs),
        );
        foreach ($inputs->timeTrials as $trial) {
            $this->trialWeeks[self::weekOf($trial['date'])] = true;
        }
    }

    /**
     * The Mondays a trial falls due, oldest first.
     *
     * @return list<string>
     */
    public static function dueWeeks(PlanInputs $inputs): array
    {
        $arcStart = $inputs->arcStart();
        $weeks = [];
        if ($inputs->raceDate !== null) {
            $week = $inputs->raceDate->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(self::LAST_TRIAL_WEEKS_BEFORE_RACE);
            for (; $week->gte($arcStart); $week = $week->copy()->subWeeks(self::CADENCE_WEEKS)) {
                array_unshift($weeks, $week->toDateString());
            }

            return $weeks;
        }

        $week = $arcStart->copy()->addWeeks(self::SELF_SCALED_FIRST_WEEK - 1);
        for (; $week->lte($inputs->seasonEnd); $week = $week->copy()->addWeeks(self::CADENCE_WEEKS)) {
            $weeks[] = $week->toDateString();
        }

        return $weeks;
    }

    /** The trial this week should hold, or null. */
    public function forWeek(Carbon $weekStart, PlanPhase $phase): ?TimeTrial
    {
        $aimTimeSec = $this->inputs->timeTrialAimSec;
        if ($aimTimeSec === null || ! $this->allows($weekStart, $phase)) {
            return null;
        }

        $week = $weekStart->toDateString();
        $distanceM = TimeTrial::distanceFor($this->inputs->raceDistanceM);
        $due = array_last(array_filter($this->dueWeeks, static fn (string $dueWeek): bool => $dueWeek <= $week));
        if ($due !== null && ! $this->cycleHasTrial($due) && ! $this->hasEvidenceSince($due)) {
            return new TimeTrial($distanceM, $aimTimeSec);
        }

        $previous = $weekStart->copy()->subWeek()->toDateString();
        $skippedLastWeek = array_any(
            $this->inputs->timeTrials,
            static fn (array $trial): bool => ! $trial['retry'] && $trial['skipped'] && self::weekOf($trial['date']) === $previous,
        );

        return $skippedLastWeek && ! isset($this->trialWeeks[$week]) && ! $this->hasEvidenceSince($previous)
            ? new TimeTrial($distanceM, $aimTimeSec, retry: true)
            : null;
    }

    public function placedIn(Carbon $weekStart): void
    {
        $this->trialWeeks[$weekStart->toDateString()] = true;
    }

    private function allows(Carbon $weekStart, PlanPhase $phase): bool
    {
        if ($phase === PlanPhase::Deload) {
            return false;
        }

        $raceDate = $this->inputs->raceDate;

        return $raceDate === null
            || (int) $weekStart->diffInWeeks($raceDate->copy()->startOfWeek(Carbon::MONDAY)) > self::TRIAL_FREE_WEEKS_BEFORE_RACE;
    }

    private function cycleHasTrial(string $due): bool
    {
        $next = array_find($this->dueWeeks, static fn (string $dueWeek): bool => $dueWeek > $due);

        return array_any(
            array_keys($this->trialWeeks),
            static fn (string $week): bool => $week >= $due && ($next === null || $week < $next),
        );
    }

    private function hasEvidenceSince(string $week): bool
    {
        $from = Carbon::parse($week)->subWeeks(self::RECENT_EVIDENCE_WEEKS)->toDateString();

        return array_any($this->inputs->timeTrialEvidenceDates, static fn (string $date): bool => $date >= $from);
    }

    private static function weekOf(string $date): string
    {
        return Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
    }
}
