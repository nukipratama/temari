<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Actions\Run\Plan\ResolvePlannedSessionsAction;
use App\Enums\PlannedSessionStatus;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Story\BriefingContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The current week as the plan asks for it from today: the km each day shows,
 * after any ease, clamp or redistribution, and the week total, which counts
 * the credited km of every past day, today's credited km once a run is
 * recorded or else its shown km, and the shown km of every later day. Home,
 * Plan's header and the narrator's planned sessions all read it. See
 * `docs/decisions/the-week-total-is-the-week-the-plan-asks-for.md`.
 */
final readonly class CurrentWeekKm
{
    public function __construct(
        private TrainingBaseline $baseline,
        private TrainingPaceCalculator $paceCalculator,
        private VdotEstimator $vdotEstimator,
        private SessionMatcher $sessionMatcher,
        private CurrentWeekVolumeProjector $volumeProjector,
        private ResolveActiveRaceAction $activeRace,
        private ResolvePlannedSessionsAction $plannedSessions,
        private PlanBriefingContext $briefing,
    ) {
    }

    /**
     * @return array{sessions: Collection<int, PlannedSession>, phase: PlanPhase, multiplier: float, baseline: array{sessions_per_week: int, weekly_volume_km: float, long_run_km: float, long_run_cap_km: float, long_run_progression_cap_km: float, self_scaled: bool}, paces: array{easy: int, marathon: int, threshold: int, interval: int}|null, briefing: BriefingContext, race: RaceGoal|null, race_distance_m: float|null, primary_easy_date: string|null, today_session: PlannedSession|null, clamp: array{session_type: SessionType, segments: list<SessionSegment>, core_km: float, note: string, quality_dose?: array{hard_minutes: int, original_hard_minutes: int, pace_band: string, pace_sec_per_km: int|null}|null}|null, scale_by_date: array<string, float>, activity_by_date: array<string, array{km: float, meters: float, runs: list<array{id: int, km: float, seconds: int|null, started_at: string}>}>, statuses: array<string, PlannedSessionStatus>, fallback_verdicts: array<string, array<string, mixed>>, km_by_date: array<string, float>, type_by_date: array<string, SessionType>, total_km: float, eased_from_total_km: float|null}|null
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
        $briefingContext = $this->briefing->forUser($user, $today);
        $loadPending = $briefingContext->historyLoading;
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

        $kmByDate = [];
        $typeByDate = [];
        $totalKm = 0.0;
        $easedAwayKm = 0.0;
        foreach ($currentWeekSessions as $s) {
            $date = $s->date->toDateString();
            $shown = PlanRenderer::shownDay(
                $s,
                $today,
                $resolvedStatuses[$date] ?? PlannedSessionStatus::Planned,
                $clamp,
                $weekProjection['scale_by_date'][$date] ?? 1.0,
                $raceDistanceM,
                $date === $primaryEasyDate,
                $baselineData['long_run_km'],
                $currentWeekMultiplier,
                $baselineData['long_run_cap_km'],
                $paces,
                null,
                $baselineData['long_run_progression_cap_km'],
                $s->date->isSameDay($today) ? $briefingContext->readinessAssessment : null,
            );
            $kmByDate[$date] = $shown['distance_km'];
            $typeByDate[$date] = $shown['session_type'];

            $creditedKm = $s->date->isAfter($today) ? null : PlanRenderer::creditedKmOf($s, $weekProjection['activity_by_date'][$date] ?? null);
            if ($s->date->lt($today) || $creditedKm !== null) {
                $totalKm += $creditedKm ?? 0.0;

                continue;
            }

            $totalKm += $shown['distance_km'];
            $easedAwayKm += $shown['eased_from_km'] === null ? 0.0 : $shown['eased_from_km'] - $shown['distance_km'];
        }
        $totalKm = round($totalKm, 1);

        return [
            'sessions' => $currentWeekSessions,
            'phase' => $currentWeekPhase,
            'multiplier' => $currentWeekMultiplier,
            'baseline' => $baselineData,
            'paces' => $paces,
            'briefing' => $briefingContext,
            'race' => $race,
            'race_distance_m' => $raceDistanceM,
            'primary_easy_date' => $primaryEasyDate,
            'today_session' => $todaySession,
            'clamp' => $clamp,
            'scale_by_date' => $weekProjection['scale_by_date'],
            'activity_by_date' => $weekProjection['activity_by_date'],
            'statuses' => $resolvedStatuses,
            'fallback_verdicts' => $fallbackVerdicts,
            'km_by_date' => $kmByDate,
            'type_by_date' => $typeByDate,
            'total_km' => $totalKm,
            'eased_from_total_km' => round($easedAwayKm, 1) > 0.0 ? round($totalKm + $easedAwayKm, 1) : null,
        ];
    }
}
