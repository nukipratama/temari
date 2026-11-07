<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlannedSessionStatus;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Metrics\RecentTrainingStress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The current week's hard-minute wallet against the cap locked at its first regeneration:
 * minutes already run, minutes still booked on the days ahead, and what is left. The Plan
 * page and Home's Today card read the same figures.
 */
final readonly class WeekHardBudget
{
    public function __construct(private RecentTrainingStress $trainingStress)
    {
    }

    /**
     * @param  Collection<int, PlannedSession>  $weekSessions
     * @param  list<string>  $ranDates
     * @return array{cap_minutes: int, spent_minutes: int, booked_minutes: int, left_minutes: int}|null
     */
    public function of(User $user, Carbon $today, ?PlanAdaptation $adaptation, Collection $weekSessions, array $ranDates): ?array
    {
        $cap = $adaptation?->hard_cap_minutes;
        if ($cap === null) {
            return null;
        }

        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $actuals = PlanInputsGatherer::budgetActuals($this->trainingStress->forUser($user, $today, (int) $weekStart->diffInDays($today) + 1)['sessions']);
        $spent = (int) round(array_sum(array_map(static fn (array $actual): float => $actual['hard_minutes'] ?? 0.0, $actuals)));
        $booked = array_sum(self::bookings($weekSessions, $today, $ranDates));

        return ['cap_minutes' => $cap, 'spent_minutes' => $spent, 'booked_minutes' => $booked, 'left_minutes' => $cap - $spent - $booked];
    }

    /**
     * Hard minutes still booked from today on, keyed by date: planned, not skipped and not
     * yet run, and only where the day holds any.
     *
     * @param  Collection<int, PlannedSession>  $weekSessions
     * @param  list<string>  $ranDates
     * @return array<string, int>
     */
    public static function bookings(Collection $weekSessions, Carbon $today, array $ranDates): array
    {
        return $weekSessions
            ->filter(fn (PlannedSession $s): bool => ! $s->date->lt($today) && ! $s->skipped
                && $s->status === PlannedSessionStatus::Planned && ! in_array($s->date->toDateString(), $ranDates, true)
                && ($s->prescribed_hard_minutes ?? 0) > 0)
            ->sortBy(fn (PlannedSession $s): string => $s->date->toDateString())
            ->mapWithKeys(fn (PlannedSession $s): array => [$s->date->toDateString() => (int) $s->prescribed_hard_minutes])
            ->all();
    }
}
