<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Actions\Run\Plan\ResolvePlannedSessionsAction;
use App\Models\User;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\AI\PlanNarrationRequester;
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
 * multiplier for the shared week.
 */
final readonly class CurrentWeekPlanBuilder
{
    /** The trailing window both this builder and PlanController read, so the week they share resolves to one multiplier. */
    public const int HISTORY_WEEKS = 3;

    public function __construct(
        private TrainingBaseline $baseline,
        private TrainingLoad $trainingLoad,
        private TrainingPaceCalculator $paceCalculator,
        private VdotEstimator $vdotEstimator,
        private SessionMatcher $sessionMatcher,
        private PlanNarrationRequester $planNarration,
        private ResolveActiveRaceAction $activeRace,
        private ResolvePlannedSessionsAction $plannedSessions,
    ) {
    }

    /**
     * @return array{sessions_this_week: int, phase: string, planned_km_this_week: float, credited_this_week: int, days: array<int, array<string, mixed>>}|null
     */
    public function forUser(User $user, Carbon $today): ?array
    {
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $currentWeekKey = $currentWeekStart->toDateString();
        $rangeStart = $currentWeekStart->copy()->subWeeks(self::HISTORY_WEEKS);
        $rangeEnd = $currentWeekStart->copy()->addDays(6);

        $sessions = ($this->plannedSessions)($user->id, $rangeStart->toDateString(), $rangeEnd->toDateString());

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
        $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($user, $today));
        $ceiling = ReadinessCeiling::from(
            BriefingContext::forUser($user, $today, $this->trainingLoad->summary($user, $today))->readinessCeiling,
        );
        $race = ($this->activeRace)($user->id);
        $raceDistanceM = $race !== null ? (float) $race->distance_m : null;
        $primaryEasyDate = PlanRenderer::primaryEasyDate($currentWeekSessions);

        $plannedKmByDate = [];
        foreach ($currentWeekSessions as $s) {
            $plannedKmByDate[$s->date->toDateString()] = SegmentGenerator::coreKmFor(
                $s->session_type,
                $s->date->toDateString() === $primaryEasyDate,
                $baselineData['long_run_km'],
                $currentWeekMultiplier,
                $s->race_distance_m === null ? null : (float) $s->race_distance_m,
            );
        }

        // Every past row should already carry its real status —
        // plan:score-compliance (daily) persists it the morning after. This
        // is the safety net for whatever it hasn't reached yet, plus today,
        // which it deliberately never reaches.
        $staleSessions = $currentWeekSessions->filter(
            fn (PlannedSession $s): bool => $s->status === PlannedSessionStatus::Planned && $s->date->lte($today),
        );
        $fallbackStatuses = [];
        if ($staleSessions->isNotEmpty()) {
            $staleDates = $staleSessions->map(fn (PlannedSession $s): string => $s->date->toDateString())->all();
            $stalePlannedKmByDate = array_intersect_key($plannedKmByDate, array_flip($staleDates));
            $staleExcused = $staleSessions->mapWithKeys(
                fn (PlannedSession $s): array => [$s->date->toDateString() => $s->isExcused()],
            )->all();
            $fallbackStatuses = $this->sessionMatcher->statuses($user, $stalePlannedKmByDate, $staleExcused, $today);
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
                $raceDistanceM,
                $baselineData['long_run_km'],
                $currentWeekMultiplier,
                $paces,
                $ceiling,
            )
            : null;

        $activityByDate = $this->sessionMatcher->activityByDate($user, $currentWeekStart, $today);
        $clampVoice = $clamp === null ? null : $this->planNarration->clampVoiceFor($user, $today);

        $days = $currentWeekSessions->map(fn (PlannedSession $s): array => PlanRenderer::dayPayload(
            $s,
            $today,
            $clamp,
            [],
            $raceDistanceM,
            $s->date->toDateString() === $primaryEasyDate,
            $baselineData['long_run_km'],
            $currentWeekMultiplier,
            $paces,
            $resolvedStatuses[$s->date->toDateString()] ?? PlannedSessionStatus::Planned,
            $activityByDate[$s->date->toDateString()] ?? null,
            $clampVoice,
            $race !== null && $s->date->isSameDay($race->race_date) ? $race->goal_time_sec : null,
        ))->values()->all();

        // A rest day asks for nothing and always scores Done, so counting it
        // would credit the athlete for a day off. The ring measures training
        // days, and against the ones this week actually holds — a plan that
        // began mid-week has fewer rows than the baseline's weekly target,
        // and a total nothing could reach is not a target.
        $trainingDates = $currentWeekSessions
            ->reject(fn (PlannedSession $s): bool => $s->session_type === SessionType::Rest)
            ->map(fn (PlannedSession $s): string => $s->date->toDateString())
            ->all();

        return [
            'sessions_this_week' => count($trainingDates),
            'phase' => $currentWeekPhase->value,
            'planned_km_this_week' => round(array_sum(array_column($days, 'distance_km')), 1),
            'credited_this_week' => count(array_filter(
                array_intersect_key($resolvedStatuses, array_flip($trainingDates)),
                static fn (PlannedSessionStatus $status): bool => $status->isCredited(),
            )),
            'days' => $days,
        ];
    }
}
