<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlannedSessionStatus;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Story\BriefingContext;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Home's "this week's plan" widget — the current week only, no lookahead and
 * no volume redistribution (Home never shows a future day's resized
 * distance, only its status glyph), but the same trailing-history window
 * {@see \App\Http\Controllers\PlanController} queries, so
 * {@see PlanRenderer::weekPhasesAndMultipliers()} computes an identical
 * multiplier for the shared week. `streak_days` is a new metric, day-grained
 * and scoped to this week only — distinct from {@see \App\Models\WeeklySnapshot::consecutiveWeekStreak()}'s
 * week-grained lifetime streak already shown on Plan.
 */
final readonly class CurrentWeekPlanBuilder
{
    /** Mirrors PlanController::HISTORY_WEEKS — must match for the shared week's multiplier to agree. */
    private const int HISTORY_WEEKS = 3;

    public function __construct(
        private TrainingBaseline $baseline,
        private TrainingLoad $trainingLoad,
        private TrainingPaceCalculator $paceCalculator,
        private VdotEstimator $vdotEstimator,
        private SessionMatcher $sessionMatcher,
    ) {
    }

    /**
     * @return array{sessions_per_week: int, phase: string, planned_km_this_week: float, credited_this_week: int, streak_days: int, days: array<int, array<string, mixed>>}|null
     */
    public function forUser(User $user, Carbon $today): ?array
    {
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $currentWeekKey = $currentWeekStart->toDateString();
        $rangeStart = $currentWeekStart->copy()->subWeeks(self::HISTORY_WEEKS);
        $rangeEnd = $currentWeekStart->copy()->addDays(6);

        $sessions = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->orderBy('date')
            ->get();

        $sessionsByWeek = $sessions->groupBy(
            fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
        );

        $currentWeekSessions = $sessionsByWeek->get($currentWeekKey);
        if ($currentWeekSessions === null || $currentWeekSessions->isEmpty()) {
            return null;
        }

        [$phaseByWeek, $multiplierByWeek] = PlanRenderer::weekPhasesAndMultipliers($sessionsByWeek);
        $currentWeekPhase = $phaseByWeek->get($currentWeekKey);
        if ($currentWeekPhase === null) {
            // Built from the same grouping as $currentWeekSessions; this only guards the type.
            throw new LogicException('The current week unexpectedly had no phase.');
        }
        $currentWeekMultiplier = $multiplierByWeek[$currentWeekKey] ?? 1.0;

        $baselineData = $this->baseline->forUser($user, $today);
        $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($user));
        $ceiling = ReadinessCeiling::from(
            BriefingContext::forUser($user, $today, $this->trainingLoad->summary($user, $today))->readinessCeiling,
        );
        $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();
        $isMarathonDistance = WeekPlanBuilder::isMarathonDistance($race !== null ? (float) $race->distance_m : null);
        $primaryEasyDate = PlanRenderer::primaryEasyDate($currentWeekSessions);

        $plannedKmByDate = [];
        foreach ($currentWeekSessions as $s) {
            $plannedKmByDate[$s->date->toDateString()] = SegmentGenerator::coreKmFor(
                $s->session_type,
                $s->date->toDateString() === $primaryEasyDate,
                $baselineData['long_run_km'],
                $currentWeekMultiplier,
            );
        }

        // Every past row should already carry its real status —
        // plan:score-compliance (daily) persists it the morning after. This
        // is only a safety net for whatever it hasn't reached yet.
        $staleSessions = $currentWeekSessions->filter(
            fn (PlannedSession $s): bool => $s->status === PlannedSessionStatus::Planned && $s->date->lt($today),
        );
        $fallbackStatuses = [];
        if ($staleSessions->isNotEmpty()) {
            $staleSkipped = $staleSessions->mapWithKeys(
                fn (PlannedSession $s): array => [$s->date->toDateString() => $s->skipped],
            )->all();
            $fallbackStatuses = $this->sessionMatcher->statuses($user, $plannedKmByDate, $staleSkipped, $today);
        }
        $resolvedStatuses = $currentWeekSessions->mapWithKeys(
            fn (PlannedSession $s): array => [
                $s->date->toDateString() => $fallbackStatuses[$s->date->toDateString()] ?? $s->status,
            ],
        )->all();

        $todaySession = $currentWeekSessions->first(fn (PlannedSession $s): bool => $s->date->isSameDay($today));
        $clamp = ($todaySession !== null && ! $todaySession->pinned)
            ? ReadinessClamp::apply(
                $todaySession->session_type,
                $todaySession->phase,
                $isMarathonDistance,
                $baselineData['long_run_km'],
                $currentWeekMultiplier,
                $paces,
                $ceiling,
            )
            : null;

        // ->values() reindexes to 0-based sequential keys — streakDays() walks
        // $days by position, which groupBy()'s preserved-original-keys
        // grouping would otherwise break.
        $days = $currentWeekSessions->map(fn (PlannedSession $s): array => PlanRenderer::dayPayload(
            $s,
            $today,
            $clamp,
            [],
            $isMarathonDistance,
            $s->date->toDateString() === $primaryEasyDate,
            $baselineData['long_run_km'],
            $currentWeekMultiplier,
            $paces,
            $resolvedStatuses[$s->date->toDateString()] ?? PlannedSessionStatus::Planned,
        ))->values()->all();

        return [
            'sessions_per_week' => $baselineData['sessions_per_week'],
            'phase' => $currentWeekPhase->value,
            'planned_km_this_week' => round(array_sum($plannedKmByDate), 1),
            'credited_this_week' => count(array_filter(
                $resolvedStatuses,
                static fn (PlannedSessionStatus $status): bool => $status->isCredited(),
            )),
            'streak_days' => $this->streakDays($days, $today),
            'days' => $days,
        ];
    }

    /**
     * Walks backward from today counting consecutive credited days. If
     * today itself isn't credited yet (still `planned`), it's skipped rather
     * than treated as a break — a streak "still building into today" reads
     * correctly instead of showing 0 on a not-yet-run day.
     *
     * @param  array<int, array<string, mixed>>  $days
     */
    private function streakDays(array $days, Carbon $today): int
    {
        $todayIso = $today->toDateString();
        $i = array_find_key($days, fn ($day) => $day['date'] === $todayIso);
        $i ??= count($days) - 1;

        if (! PlannedSessionStatus::from($days[$i]['status'])->isCredited()) {
            $i--;
        }

        $count = 0;
        for (; $i >= 0 && PlannedSessionStatus::from($days[$i]['status'])->isCredited(); $i--) {
            $count++;
        }

        return $count;
    }
}
