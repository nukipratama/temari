<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlannedSessionStatus;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\DistanceFormatter;
use Illuminate\Support\Carbon;

/**
 * Matches what the athlete actually ran against what the plan asked for,
 * one day at a time. A day is judged on km run vs km prescribed, not on
 * "was there any activity at all", so a 3 km jog against a 20 km long run
 * reads as {@see PlannedSessionStatus::Partial} rather than done.
 *
 * Loads a whole date range in one query — the per-day existence check this
 * replaced on the Plan tab was an N+1 across every rendered week.
 */
final class SessionMatcher
{
    /** Fraction of the prescribed km that counts the session as run as asked. */
    public const float DONE_FRACTION = 0.85;

    /** Below this fraction the session counts as missed, not partially done. */
    public const float PARTIAL_FRACTION = 0.35;

    /**
     * @param  array<string, float>  $plannedKmByDate  Y-m-d => prescribed km (0.0 on a rest day)
     * @return array<string, PlannedSessionStatus>  Y-m-d => status
     */
    public function statuses(User $user, array $plannedKmByDate, Carbon $today): array
    {
        if ($plannedKmByDate === []) {
            return [];
        }

        return $this->statusesFrom($plannedKmByDate, $this->completedKmByDate($user, $plannedKmByDate), $today);
    }

    /**
     * How much of a week the athlete actually got through. `adherence` is the
     * completed share of the week's elapsed *sessions* (rest days excluded,
     * since they ask for nothing), which is what {@see PlanAdapter} reacts
     * to; the km figures are for copy.
     *
     * @param  array<string, float>  $plannedKmByDate  Y-m-d => prescribed km
     * @return array{planned_km: float, completed_km: float, planned_sessions: int, completed_sessions: int, adherence: float}
     */
    public function weekAdherence(User $user, array $plannedKmByDate, Carbon $today): array
    {
        if ($plannedKmByDate === []) {
            return ['planned_km' => 0.0, 'completed_km' => 0.0, 'planned_sessions' => 0, 'completed_sessions' => 0, 'adherence' => 1.0];
        }

        $completedKm = $this->completedKmByDate($user, $plannedKmByDate);
        $statuses = $this->statusesFrom($plannedKmByDate, $completedKm, $today);

        $plannedSessions = 0;
        $completedSessions = 0;
        $plannedKm = 0.0;
        foreach ($plannedKmByDate as $date => $km) {
            if ($km <= 0.0 || $statuses[$date] === PlannedSessionStatus::Planned) {
                continue;
            }
            $plannedSessions++;
            $plannedKm += $km;
            if ($statuses[$date]->isCredited()) {
                $completedSessions++;
            }
        }

        return [
            'planned_km' => round($plannedKm, 1),
            'completed_km' => round(array_sum($completedKm), 1),
            'planned_sessions' => $plannedSessions,
            'completed_sessions' => $completedSessions,
            'adherence' => $plannedSessions === 0 ? 1.0 : round($completedSessions / $plannedSessions, 3),
        ];
    }

    public static function statusFor(float $plannedKm, float $completedKm, bool $isPast): PlannedSessionStatus
    {
        if (! $isPast) {
            return PlannedSessionStatus::Planned;
        }
        if ($plannedKm <= 0.0) {
            return PlannedSessionStatus::Done;
        }

        $ratio = $completedKm / $plannedKm;

        return match (true) {
            $ratio >= self::DONE_FRACTION => PlannedSessionStatus::Done,
            $ratio >= self::PARTIAL_FRACTION => PlannedSessionStatus::Partial,
            default => PlannedSessionStatus::Missed,
        };
    }

    /**
     * @param  array<string, float>  $plannedKmByDate
     * @param  array<string, float>  $completedKm
     * @return array<string, PlannedSessionStatus>
     */
    private function statusesFrom(array $plannedKmByDate, array $completedKm, Carbon $today): array
    {
        $statuses = [];
        foreach ($plannedKmByDate as $date => $plannedKm) {
            $statuses[$date] = self::statusFor($plannedKm, $completedKm[$date] ?? 0.0, Carbon::parse($date)->lt($today));
        }

        return $statuses;
    }

    /**
     * @param  non-empty-array<string, float>  $plannedKmByDate
     * @return array<string, float>  Y-m-d => km run that day
     */
    private function completedKmByDate(User $user, array $plannedKmByDate): array
    {
        $dates = array_keys($plannedKmByDate);

        /** @var array<string, mixed> $rows */
        $rows = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->whereBetween('activity_details.start_date_local', [
                Carbon::parse(min($dates))->startOfDay(),
                Carbon::parse(max($dates))->endOfDay(),
            ])
            ->selectRaw('DATE(activity_details.start_date_local) as d, SUM(activity_details.distance) as meters')
            ->groupBy('d')
            ->pluck('meters', 'd')
            ->all();

        return array_map(static fn (mixed $meters): float => DistanceFormatter::km((float) $meters), $rows);
    }
}
