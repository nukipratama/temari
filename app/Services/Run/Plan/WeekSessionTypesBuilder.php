<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Plan\ResolvePlannedSessionsAction;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * This week's training days as weekday + type + distance — what Profile's pace
 * ladder needs to say which paces the week actually asks for.
 *
 * Reads the same rows over the same trailing window {@see CurrentWeekPlanBuilder}
 * does, and sizes each day through {@see PlanRenderer::sessionDistanceKm()},
 * the same helper {@see PlanRenderer::dayPayload()} sizes Home's days with, so
 * a distance here cannot disagree with the one Home shows for that day.
 */
final readonly class WeekSessionTypesBuilder
{
    public function __construct(
        private TrainingBaseline $baseline,
        private ResolvePlannedSessionsAction $plannedSessions,
    ) {
    }

    /**
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @return list<array{weekday: string, session_type: string, distance_km: float}>
     */
    public function forUser(User $user, Carbon $today, ?array $paces, ?float $activeRaceDistanceM): array
    {
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $weekKey = $weekStart->toDateString();

        $sessions = ($this->plannedSessions)(
            $user->id,
            $weekStart->copy()->subWeeks(CurrentWeekPlanBuilder::HISTORY_WEEKS)->toDateString(),
            $weekStart->copy()->addDays(6)->toDateString(),
        );

        $sessionsByWeek = $sessions->groupBy(
            fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
        );

        $week = $sessionsByWeek->get($weekKey);
        if ($week === null || $week->isEmpty()) {
            return [];
        }

        [$phaseByWeek, $multiplierByWeek] = PlanRenderer::weekPhasesAndMultipliers($sessionsByWeek);
        $multiplier = $multiplierByWeek[$weekKey] ?? 1.0;
        $longRunKm = $this->baseline->forUser($user, $today)['long_run_km'];
        $primaryEasyDate = PlanRenderer::primaryEasyDate($week);

        return array_values($week
            ->reject(fn (PlannedSession $s): bool => $s->session_type === SessionType::Rest)
            ->sortBy(fn (PlannedSession $s): string => $s->date->toDateString())
            ->map(fn (PlannedSession $s): array => [
                'weekday' => strtolower($s->date->format('D')),
                'session_type' => $s->session_type->value,
                'distance_km' => $this->distanceKm($s, $primaryEasyDate, $longRunKm, $multiplier, $paces, $activeRaceDistanceM),
            ])
            ->all());
    }

    /**
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     */
    private function distanceKm(
        PlannedSession $session,
        ?string $primaryEasyDate,
        float $longRunKm,
        float $multiplier,
        ?array $paces,
        ?float $activeRaceDistanceM,
    ): float {
        $isPrimaryEasy = $session->date->toDateString() === $primaryEasyDate;
        $raceDistanceM = $session->race_distance_m === null ? $activeRaceDistanceM : (float) $session->race_distance_m;

        $segments = SegmentGenerator::generate(
            $session->session_type,
            $session->phase,
            $raceDistanceM,
            $isPrimaryEasy,
            $longRunKm,
            $multiplier,
            $paces,
        );

        return PlanRenderer::sessionDistanceKm(
            $segments,
            $session->session_type,
            $isPrimaryEasy,
            $longRunKm,
            $multiplier,
            $raceDistanceM,
        );
    }
}
