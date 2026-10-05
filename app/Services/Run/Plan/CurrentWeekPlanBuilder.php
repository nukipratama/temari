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
use App\Services\AI\HydrationBacklog;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Story\BriefingContext;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Home's "this week's plan" widget — the current week only, no lookahead,
 * with the same current-week volume projection as {@see PlanPageAssembler}.
 * Both callers query the same trailing-history window, so
 * {@see PlanRenderer::weekPhasesAndMultipliers()} computes an identical
 * multiplier for the shared week.
 */
final readonly class CurrentWeekPlanBuilder
{
    public function __construct(
        private TrainingBaseline $baseline,
        private TrainingLoad $trainingLoad,
        private TrainingPaceCalculator $paceCalculator,
        private VdotEstimator $vdotEstimator,
        private SessionMatcher $sessionMatcher,
        private CurrentWeekVolumeProjector $volumeProjector,
        private PlanNarrationRequester $planNarration,
        private ResolveActiveRaceAction $activeRace,
        private ResolvePlannedSessionsAction $plannedSessions,
        private HydrationBacklog $hydrationBacklog,
        private RaceAmbitionAssessor $ambition,
    ) {
    }

    /**
     * @return array{sessions_this_week: int, phase: string, planned_km_this_week: float, planned_km_eased_from: float|null, credited_this_week: int, days: array<int, array<string, mixed>>}|null
     */
    public function forUser(User $user, Carbon $today): ?array
    {
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $currentWeekKey = $currentWeekStart->toDateString();
        $rangeStart = $currentWeekStart->copy()->subWeeks(PlanRenderer::HISTORY_WEEKS);
        $rangeEnd = $currentWeekStart->copy()->addDays(6);

        $sessions = ($this->plannedSessions)($user->id, $rangeStart->toDateString(), $rangeEnd->toDateString());

        $sessionsByWeek = $sessions->groupBy(
            fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
        );

        $currentWeekSessions = $sessionsByWeek->get($currentWeekKey);
        if ($currentWeekSessions === null || $currentWeekSessions->isEmpty()) {
            return null;
        }

        $baselineData = $this->baseline->forUser($user, $today);

        [$phaseByWeek, $multiplierByWeek] = PlanRenderer::weekPhasesAndMultipliers($sessionsByWeek, $baselineData['self_scaled']);
        $currentWeekPhase = $phaseByWeek->get($currentWeekKey);
        if ($currentWeekPhase === null) {
            // Built from the same grouping as $currentWeekSessions; this only guards the type.
            throw new LogicException('The current week unexpectedly had no phase.');
        }
        $currentWeekMultiplier = $multiplierByWeek[$currentWeekKey] ?? 1.0;

        $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($user, $today));
        $loadPending = $this->hydrationBacklog->recentLoadAwaitsScoring($user->id, $today);
        $briefingContext = BriefingContext::forUser(
            $user,
            $today,
            $loadPending ? null : $this->trainingLoad->summary($user, $today),
            historyLoading: $loadPending,
        );
        $ceiling = ReadinessCeiling::from($briefingContext->readinessCeiling);
        $race = ($this->activeRace)($user->id);
        $raceDistanceM = $race !== null ? (float) $race->distance_m : null;
        $primaryEasyDate = PlanRenderer::primaryEasyDate($currentWeekSessions);

        $plannedKmByDate = [];
        foreach ($currentWeekSessions as $s) {
            $effective = EffectiveSession::of($s, SegmentGenerator::coreKmFor(
                $s->session_type,
                $s->date->toDateString() === $primaryEasyDate,
                $baselineData['long_run_km'],
                $currentWeekMultiplier,
                $baselineData['long_run_cap_km'],
                $s->race_distance_m === null ? null : (float) $s->race_distance_m,
                $baselineData['long_run_progression_cap_km'],
                $s->fall_off_tilt,
                $s->prescription_race_context,
            ));
            $plannedKmByDate[$s->date->toDateString()] = $effective->coreKm;
        }

        // Every past row should already carry its real status —
        // plan:score-compliance (daily) persists it the morning after. This
        // is the safety net for whatever it hasn't reached yet, plus today,
        // which it deliberately never reaches.
        $staleSessions = $currentWeekSessions->filter(
            fn (PlannedSession $s): bool => $s->status === PlannedSessionStatus::Planned && $s->date->lte($today),
        );
        $fallbackVerdicts = [];
        if ($staleSessions->isNotEmpty()) {
            $staleDates = $staleSessions->map(fn (PlannedSession $s): string => $s->date->toDateString())->all();
            $stalePlannedKmByDate = array_intersect_key($plannedKmByDate, array_flip($staleDates));
            $staleExcused = $staleSessions->mapWithKeys(
                fn (PlannedSession $s): array => [$s->date->toDateString() => $s->isExcused()],
            )->all();
            $fallbackVerdicts = $this->sessionMatcher->scoreRange($user, $stalePlannedKmByDate, $staleExcused, $today);
        }
        $resolvedStatuses = $currentWeekSessions->mapWithKeys(
            fn (PlannedSession $s): array => [
                $s->date->toDateString() => $fallbackVerdicts[$s->date->toDateString()]['status'] ?? $s->status,
            ],
        )->all();


        $todaySession = $currentWeekSessions->first(fn (PlannedSession $s): bool => $s->date->isSameDay($today));
        $strongHealthConcern = array_intersect(
            $briefingContext->readinessAssessment['reasons'],
            ['concerning_pain_reported', 'illness_reported'],
        ) !== [];
        $clamp = ($todaySession !== null && (! $loadPending || $strongHealthConcern))
            ? ReadinessClamp::apply(
                $todaySession->session_type,
                $todaySession->phase,
                $todaySession->race_distance_m === null ? $raceDistanceM : (float) $todaySession->race_distance_m,
                $baselineData['long_run_km'],
                $currentWeekMultiplier,
                $baselineData['long_run_cap_km'],
                $paces,
                $ceiling,
                $baselineData['long_run_progression_cap_km'],
                $briefingContext->readinessAssessment['reasons'],
                IntensityPrescription::fromSession($todaySession),
            )
            : null;

        $weekProjection = $this->volumeProjector->project(
            $user,
            $currentWeekSessions,
            $currentWeekStart,
            $today,
            $baselineData['long_run_km'],
            $currentWeekMultiplier,
            $baselineData['long_run_cap_km'],
            $baselineData['long_run_progression_cap_km'],
            $primaryEasyDate,
            $todaySession,
            $clamp,
        );
        $volumeScaleByDate = $weekProjection['scale_by_date'];
        $activityByDate = $weekProjection['activity_by_date'];

        $clampVoice = EffectiveSession::clampVoiceNeeded($clamp, $todaySession)
            ? $this->planNarration->clampVoiceFor($user, $today)
            : null;

        $days = $currentWeekSessions->map(fn (PlannedSession $s): array => PlanRenderer::dayPayload(
            $s,
            $today,
            $clamp,
            $volumeScaleByDate,
            $raceDistanceM,
            $s->date->toDateString() === $primaryEasyDate,
            $baselineData['long_run_km'],
            $currentWeekMultiplier,
            $baselineData['long_run_cap_km'],
            $paces,
            $resolvedStatuses[$s->date->toDateString()] ?? PlannedSessionStatus::Planned,
            $activityByDate[$s->date->toDateString()] ?? null,
            $clampVoice,
            $race !== null && $s->date->isSameDay($race->race_date) ? $this->ambition->assess($user, $race, $today)->prescribedTimeSec() : null,
            $baselineData['long_run_progression_cap_km'],
            $fallbackVerdicts[$s->date->toDateString()]['ran_anyway'] ?? null,
            $s->date->isSameDay($today) ? $briefingContext->readinessAssessment : null,
            $user->runnerProfile?->easyHrCapBpm(),
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

        $plannedKmThisWeek = round(array_sum(array_column($days, 'distance_km')), 1);
        $easedAwayKm = array_sum(array_map(
            static fn (array $day): float => isset($day['eased_from']['distance_km']) ? $day['eased_from']['distance_km'] - $day['distance_km'] : 0.0,
            $days,
        ));

        return [
            'sessions_this_week' => count($trainingDates),
            'phase' => $currentWeekPhase->value,
            'planned_km_this_week' => $plannedKmThisWeek,
            'planned_km_eased_from' => round($easedAwayKm, 1) > 0.0 ? round($plannedKmThisWeek + $easedAwayKm, 1) : null,
            'credited_this_week' => count(array_filter(
                array_intersect_key($resolvedStatuses, array_flip($trainingDates)),
                static fn (PlannedSessionStatus $status): bool => $status->isCredited(),
            )),
            'days' => $days,
        ];
    }
}
