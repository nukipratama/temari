<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Actions\Run\Plan\ResolveWeekAdaptationAction;
use App\Enums\PlannedSessionStatus;
use App\Models\PlannedSession;
use App\Models\Season;
use App\Models\User;
use App\Services\Gamification\SeasonPayloadBuilder;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Assembles every prop the Plan tab renders, so
 * {@see \App\Http\Controllers\PlanController} wires Inertia rather than the
 * plan engine. The readiness clamp and volume redistribution are applied
 * here at render time only — the stored {@see PlannedSession} rows are never
 * mutated by a page load. See `docs/features/plan-periodizer.md`.
 *
 * Bound `scoped()` in AppServiceProvider: the season is memoized because
 * three props want it and `ensureCurrent()` writes, and Inertia's partial
 * reload runs the whole action a second time.
 */
final class PlanPageAssembler
{
    private const int LOOKAHEAD_WEEKS = 4;

    private ?Season $season = null;

    public function __construct(
        private readonly TrainingBaseline $baseline,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
        private readonly SeasonService $seasonService,
        private readonly SeasonPayloadBuilder $seasonPayloadBuilder,
        private readonly SeasonSummaryBuilder $seasonSummaryBuilder,
        private readonly SessionMatcher $sessionMatcher,
        private readonly ClampVoiceReader $clampVoiceReader,
        private readonly PlanRegenerateCooldown $regenerateCooldown,
        private readonly ResolveActiveRaceAction $activeRace,
        private readonly ResolveWeekAdaptationAction $weekAdaptation,
        private readonly PlanBriefingContext $briefing,
        private readonly CurrentWeekVolumeProjector $volumeProjector,
        private readonly RaceAmbitionAssessor $ambition,
    ) {
    }

    /**
     * @return array{race_date: string, name: string|null}|null
     */
    public function race(User $user): ?array
    {
        $race = ($this->activeRace)($user->id);

        return $race === null ? null : ['race_date' => $race->race_date->toDateString(), 'name' => $race->name];
    }

    public function sessionsPerWeek(User $user, Carbon $today): int
    {
        return $this->baseline->forUser($user, $today)['sessions_per_week'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function season(User $user, Carbon $today): ?array
    {
        $season = $this->currentSeason($user, $today);
        $payload = $this->seasonPayloadBuilder->seasonPayload($season, $today);

        return $payload === null ? null : [...$payload, 'under_ready_line' => $this->seasonService->takeUnderReadyLine($season)];
    }

    /**
     * @return list<array{week_start: string, phase: string, type: string, planned_km: float, eased_from_km: float|null, actual_km: float|null, sessions: int}>
     */
    public function seasonSummary(User $user, Carbon $today): array
    {
        return $this->seasonSummaryBuilder->build($user, $this->currentSeason($user, $today), $today);
    }

    public function seasonAdherencePct(User $user, Carbon $today): ?int
    {
        return $this->seasonSummaryBuilder->adherencePct($user, $this->currentSeason($user, $today));
    }

    /**
     * A deload that took the week below the race season's volume floor says
     * so, with the floor it set aside, and a block holding its increases for
     * unscored load says that too.
     *
     * @return array{reason: string, headline: string, detail: string, deload: bool}|null
     */
    public function adaptation(User $user, Carbon $today): ?array
    {
        $adaptation = ($this->weekAdaptation)($user->id, $this->currentWeekStart($today)->toDateString());
        if ($adaptation === null) {
            return null;
        }

        $detail = $adaptation->reason->detail(
            $adaptation->adherence_pct,
            $adaptation->stimulus_adherence_pct,
            $adaptation->quality_delta,
        );
        if ($adaptation->volume_floor_km !== null) {
            $detail .= ' that puts it under your usual '.number_format($adaptation->volume_floor_km, 1).' km a week, on purpose.';
        }
        if ($adaptation->increases_held) {
            $detail .= ' the build and the longer long runs wait until your recent runs are scored.';
        }

        return [
            'reason' => $adaptation->reason->value,
            'headline' => $adaptation->reason->headline(),
            'detail' => $detail,
            'deload' => $adaptation->deload,
        ];
    }

    public function regenerateCooldownSeconds(User $user): ?int
    {
        return $this->regenerateCooldown->remaining($user);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function weeks(User $user, Carbon $today): array
    {
        $currentWeekStart = $this->currentWeekStart($today);
        $rangeStart = $currentWeekStart->copy()->subWeeks(PlanRenderer::HISTORY_WEEKS);
        $rangeEnd = $currentWeekStart->copy()->addWeeks(self::LOOKAHEAD_WEEKS)->addDays(6);

        $ruleRows = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$rangeStart->copy()->subDay()->toDateString(), $rangeEnd->copy()->addDay()->toDateString()])
            ->orderBy('date')
            ->get();
        $ruleRowsByDate = $ruleRows->keyBy(fn (PlannedSession $row): string => $row->date->toDateString());
        $sessions = $ruleRows
            ->filter(fn (PlannedSession $s): bool => $s->date->betweenIncluded($rangeStart, $rangeEnd))
            ->values();

        $race = ($this->activeRace)($user->id);
        $baselineData = $this->baseline->forUser($user, $today);
        $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($user));
        $briefingContext = $this->briefing->forUser($user, $today);
        $loadPending = $briefingContext->historyLoading;
        $ceiling = ReadinessCeiling::from($briefingContext->readinessCeiling);
        $raceDistanceM = $race !== null ? (float) $race->distance_m : null;

        $sessionsByWeek = $sessions->groupBy(
            fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
        );

        [$phaseByWeek, $multiplierByWeek] = PlanRenderer::weekPhasesAndMultipliers($sessionsByWeek, $baselineData['self_scaled']);
        $primaryEasyDateByWeek = $sessionsByWeek->map(fn (Collection $weekSessions): ?string => PlanRenderer::primaryEasyDate($weekSessions));

        $currentWeekKey = $currentWeekStart->toDateString();

        $fallbackVerdicts = $this->fallbackVerdicts($user, $sessions, $today, $baselineData, $multiplierByWeek, $primaryEasyDateByWeek);

        // Readiness clamp: TODAY's row only — a future day's readiness isn't
        // knowable today, so clamping never reaches past this one row.
        $todaySession = $sessions->first(fn (PlannedSession $s): bool => $s->date->isSameDay($today));
        $clamp = ($todaySession !== null && ! $loadPending)
            ? ReadinessClamp::apply(
                $todaySession->session_type,
                $todaySession->phase,
                $todaySession->race_distance_m === null ? $raceDistanceM : (float) $todaySession->race_distance_m,
                $baselineData['long_run_km'],
                $multiplierByWeek[$currentWeekKey] ?? 1.0,
                $baselineData['long_run_cap_km'],
                $paces,
                $ceiling,
                $baselineData['long_run_progression_cap_km'],
                $briefingContext->readinessAssessment['reasons'],
                IntensityPrescription::fromSession($todaySession),
            )
            : null;

        // Falls back to the clamp's own templated note when no line has landed
        // yet, so the step-down is never unexplained.
        $clampVoice = EffectiveSession::clampVoiceNeeded($clamp, $todaySession)
            ? $this->clampVoiceReader->clampVoiceFor($user, $today)
            : null;

        $weekProjection = $this->volumeProjector->project(
            $user,
            $sessionsByWeek->get($currentWeekKey, collect()),
            $rangeStart,
            $today,
            $baselineData['long_run_km'],
            $multiplierByWeek[$currentWeekKey] ?? 1.0,
            $baselineData['long_run_cap_km'],
            $baselineData['long_run_progression_cap_km'],
            $primaryEasyDateByWeek->get($currentWeekKey),
            $todaySession,
            $clamp,
        );
        $volumeScaleByDate = $weekProjection['scale_by_date'];
        $activityByDate = $weekProjection['activity_by_date'];
        $ranDates = array_map(strval(...), array_keys($activityByDate));

        $weeks = [];
        foreach ($sessionsByWeek as $weekStartKey => $weekSessions) {
            $weekPhase = $phaseByWeek->get($weekStartKey);
            if ($weekPhase === null) {
                // Built from the same grouping as $sessionsByWeek; this only guards the type.
                throw new LogicException('A grouped week unexpectedly had no phase.');
            }
            $primaryEasyDate = $primaryEasyDateByWeek->get($weekStartKey);

            $weeks[] = [
                'week_start' => $weekStartKey,
                'phase' => $weekPhase->value,
                'type' => $weekStartKey < $currentWeekKey ? 'history' : ($weekStartKey === $currentWeekKey ? 'current' : 'lookahead'),
                'days' => $weekSessions->map(fn (PlannedSession $s): array => [...PlanRenderer::dayPayload(
                    $s,
                    $today,
                    $clamp,
                    $volumeScaleByDate,
                    $raceDistanceM,
                    $s->date->toDateString() === $primaryEasyDate,
                    $baselineData['long_run_km'],
                    $multiplierByWeek[$weekStartKey] ?? 1.0,
                    $baselineData['long_run_cap_km'],
                    $paces,
                    $fallbackVerdicts[$s->date->toDateString()]['status'] ?? $s->status,
                    $activityByDate[$s->date->toDateString()] ?? null,
                    $clampVoice,
                    $race !== null && $s->date->isSameDay($race->race_date) ? $this->ambition->assess($user, $race, $today)->prescribedTimeSec() : null,
                    $baselineData['long_run_progression_cap_km'],
                    $fallbackVerdicts[$s->date->toDateString()]['ran_anyway'] ?? null,
                    $s->date->isSameDay($today) ? $briefingContext->readinessAssessment : null,
                    $user->runnerProfile?->easyHrCapBpm(),
                ), ...$this->editRules($s, $fallbackVerdicts[$s->date->toDateString()]['status'] ?? $s->status, $ruleRowsByDate, $ranDates, $today), 'made_up_on' => $s->made_up_on?->toDateString()])->all(),
            ];
        }

        return $weeks;
    }

    /**
     * @param  Collection<string, PlannedSession>  $ruleRowsByDate
     * @param  list<string>  $ranDates
     * @return array{actions: array{move: bool, skip: bool, restore: bool}, move_targets: list<string>}
     */
    private function editRules(PlannedSession $day, PlannedSessionStatus $status, Collection $ruleRowsByDate, array $ranDates, Carbon $today): array
    {
        [$from, $to] = SessionEditRules::window($day->date);
        $fromKey = $from->toDateString();
        $toKey = $to->toDateString();
        $window = $ruleRowsByDate->filter(fn (PlannedSession $row, string $date): bool => $date >= $fromKey && $date <= $toKey)->values();

        return SessionEditRules::rulesFor($day, $status, $window, $ranDates, $today);
    }

    private function currentSeason(User $user, Carbon $today): Season
    {
        return $this->season ??= $this->seasonService->ensureCurrent($user, $today);
    }

    private function currentWeekStart(Carbon $today): Carbon
    {
        return $today->copy()->startOfWeek(Carbon::MONDAY);
    }

    /**
     * Every past row should already carry its real status —
     * plan:score-compliance (daily) persists it the morning after. This is the
     * safety net for whatever it hasn't reached yet, plus today, which it
     * deliberately never reaches; both are a small subset, so it's computed
     * for those dates rather than the whole range.
     *
     * @param  Collection<int, PlannedSession>  $sessions
     * @param  array{sessions_per_week: int, weekly_volume_km: float, long_run_km: float, long_run_cap_km: float, long_run_progression_cap_km: float, self_scaled: bool}  $baselineData
     * @param  array<string, float>  $multiplierByWeek
     * @param  Collection<string, string|null>  $primaryEasyDateByWeek
     * @return array<string, array{status: PlannedSessionStatus, score: int|null, ran_anyway: bool}>
     */
    private function fallbackVerdicts(
        User $user,
        Collection $sessions,
        Carbon $today,
        array $baselineData,
        array $multiplierByWeek,
        Collection $primaryEasyDateByWeek,
    ): array {
        $staleSessions = $sessions->filter(
            fn (PlannedSession $s): bool => $s->status === PlannedSessionStatus::Planned && $s->date->lte($today),
        );
        if ($staleSessions->isEmpty()) {
            return [];
        }

        $stalePlannedKm = [];
        $staleExcused = [];
        foreach ($staleSessions as $s) {
            $date = $s->date->toDateString();
            $weekKey = $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
            $stalePlannedKm[$date] = EffectiveSession::of($s, SegmentGenerator::coreKmFor(
                $s->session_type,
                $date === $primaryEasyDateByWeek->get($weekKey),
                $baselineData['long_run_km'],
                $multiplierByWeek[$weekKey] ?? 1.0,
                $baselineData['long_run_cap_km'],
                $s->race_distance_m === null ? null : (float) $s->race_distance_m,
                $baselineData['long_run_progression_cap_km'],
                $s->fall_off_tilt,
                $s->prescription_race_context,
            ))->coreKm;
            $staleExcused[$date] = $s->isExcused();
        }

        return $this->sessionMatcher->scoreRange($user, $stalePlannedKm, $staleExcused, $today);
    }

}
