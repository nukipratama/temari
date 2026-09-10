<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Actions\Run\Plan\ResolveWeekAdaptationAction;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\Season;
use App\Models\User;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Gamification\SeasonStreakSummaryBuilder;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Story\BriefingContext;
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
        private readonly TrainingLoad $trainingLoad,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
        private readonly SeasonService $seasonService,
        private readonly SeasonStreakSummaryBuilder $seasonStreakBuilder,
        private readonly SeasonSummaryBuilder $seasonSummaryBuilder,
        private readonly SessionMatcher $sessionMatcher,
        private readonly PlanNarrationRequester $narrationRequester,
        private readonly ResolveActiveRaceAction $activeRace,
        private readonly ResolveWeekAdaptationAction $weekAdaptation,
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
        return $this->seasonStreakBuilder->seasonPayload($user, $this->currentSeason($user, $today), $today);
    }

    /**
     * @return list<array{week_start: string, phase: string, type: string, planned_km: float, actual_km: float|null, sessions: int}>
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
     * @return array{reason: string, headline: string, detail: string, deload: bool}|null
     */
    public function adaptation(User $user, Carbon $today): ?array
    {
        $adaptation = ($this->weekAdaptation)($user->id, $this->currentWeekStart($today)->toDateString());

        return $adaptation === null ? null : [
            'reason' => $adaptation->reason->value,
            'headline' => $adaptation->reason->headline(),
            'detail' => $adaptation->reason->detail($adaptation->adherence_pct),
            'deload' => $adaptation->deload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function planNarration(User $user, Carbon $today): array
    {
        // Demo is excluded from plan:regenerate's real narration dispatch (no
        // LLM billing for the public account), so its Plan page fills any gap
        // with the same rule-based path its manual "Reread" already resolves
        // through — otherwise the demo would show perpetually-Pending blocks.
        if ($user->is_demo) {
            $this->narrationRequester->ensureDemoFilled($user, $today);
        }

        return $this->narrationRequester->payloadsForCurrentWeek($user, $today);
    }

    public function regenerateCooldownSeconds(User $user): ?int
    {
        return $this->narrationRequester->regenerateCooldownRemaining($user);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function weeks(User $user, Carbon $today): array
    {
        $currentWeekStart = $this->currentWeekStart($today);
        $rangeStart = $currentWeekStart->copy()->subWeeks(CurrentWeekPlanBuilder::HISTORY_WEEKS);
        $rangeEnd = $currentWeekStart->copy()->addWeeks(self::LOOKAHEAD_WEEKS)->addDays(6);

        $sessions = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->orderBy('date')
            ->get();

        $race = ($this->activeRace)($user->id);
        $baselineData = $this->baseline->forUser($user, $today);
        $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($user));
        $ceiling = ReadinessCeiling::from(
            BriefingContext::forUser($user, $today, $this->trainingLoad->summary($user, $today))->readinessCeiling,
        );
        $raceDistanceM = $race !== null ? (float) $race->distance_m : null;

        $sessionsByWeek = $sessions->groupBy(
            fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
        );

        [$phaseByWeek, $multiplierByWeek] = PlanRenderer::weekPhasesAndMultipliers($sessionsByWeek);
        $primaryEasyDateByWeek = $sessionsByWeek->map(fn (Collection $weekSessions): ?string => PlanRenderer::primaryEasyDate($weekSessions));

        $currentWeekKey = $currentWeekStart->toDateString();

        $fallbackStatuses = $this->fallbackStatuses($user, $sessions, $today, $baselineData, $multiplierByWeek, $primaryEasyDateByWeek);

        // Readiness clamp: TODAY's row only — a future day's readiness isn't
        // knowable today, so clamping never reaches past this one row.
        $todaySession = $sessions->first(fn (PlannedSession $s): bool => $s->date->isSameDay($today));
        $clamp = ($todaySession !== null && ! $todaySession->pinned)
            ? ReadinessClamp::apply(
                $todaySession->session_type,
                $todaySession->phase,
                $raceDistanceM,
                $baselineData['long_run_km'],
                $multiplierByWeek[$currentWeekKey] ?? 1.0,
                $paces,
                $ceiling,
            )
            : null;

        // Falls back to the clamp's own templated note when no line has landed
        // yet, so the step-down is never unexplained.
        $clampVoice = $clamp === null ? null : $this->narrationRequester->clampVoiceFor($user, $today);

        $volumeScaleByDate = $this->redistributeCurrentWeek(
            $user,
            $sessionsByWeek->get($currentWeekKey, collect()),
            $today,
            $currentWeekStart,
            $baselineData['long_run_km'],
            $multiplierByWeek[$currentWeekKey] ?? 1.0,
            $primaryEasyDateByWeek->get($currentWeekKey),
            $todaySession,
            $clamp,
        );

        $activityByDate = $this->sessionMatcher->activityByDate($user, $rangeStart, $today);

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
                'days' => $weekSessions->map(fn (PlannedSession $s) => PlanRenderer::dayPayload(
                    $s,
                    $today,
                    $clamp,
                    $volumeScaleByDate,
                    $raceDistanceM,
                    $s->date->toDateString() === $primaryEasyDate,
                    $baselineData['long_run_km'],
                    $multiplierByWeek[$weekStartKey] ?? 1.0,
                    $paces,
                    $fallbackStatuses[$s->date->toDateString()] ?? $s->status,
                    $activityByDate[$s->date->toDateString()] ?? null,
                    $clampVoice,
                    $race !== null && $s->date->isSameDay($race->race_date) ? $race->goal_time_sec : null,
                ))->all(),
            ];
        }

        return $weeks;
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
     * @param  array{sessions_per_week: int, weekly_volume_km: float, long_run_km: float}  $baselineData
     * @param  array<string, float>  $multiplierByWeek
     * @param  Collection<string, string|null>  $primaryEasyDateByWeek
     * @return array<string, PlannedSessionStatus>
     */
    private function fallbackStatuses(
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
            $stalePlannedKm[$date] = SegmentGenerator::coreKmFor(
                $s->session_type,
                $date === $primaryEasyDateByWeek->get($weekKey),
                $baselineData['long_run_km'],
                $multiplierByWeek[$weekKey] ?? 1.0,
                $s->race_distance_m === null ? null : (float) $s->race_distance_m,
            );
            $staleExcused[$date] = $s->isExcused();
        }

        return $this->sessionMatcher->statuses($user, $stalePlannedKm, $staleExcused, $today);
    }

    /**
     * A `Race` day carries no redistributable volume — `$kmFor` is deliberately
     * race-blind, so the event contributes nothing to the week's target and is
     * never scaled itself. The race is whatever distance it is; what
     * redistributes is the training around it.
     *
     * @param  Collection<int, PlannedSession>  $currentWeekSessions
     * @param array{session_type: SessionType, segments: list<SessionSegment>, core_km: float, note: string}|null $clamp
     * @return array<string, float>  date => volume scale, from {@see VolumeRedistributor::redistribute()}
     */
    private function redistributeCurrentWeek(
        User $user,
        Collection $currentWeekSessions,
        Carbon $today,
        Carbon $currentWeekStart,
        float $longRunKm,
        float $multiplier,
        ?string $primaryEasyDate,
        ?PlannedSession $todaySession,
        ?array $clamp,
    ): array {
        if ($currentWeekSessions->isEmpty()) {
            return [];
        }

        $kmFor = fn (PlannedSession $s): float => SegmentGenerator::coreKmFor(
            $s->session_type,
            $s->date->toDateString() === $primaryEasyDate,
            $longRunKm,
            $multiplier,
        );

        $weekTargetKm = $currentWeekSessions->sum($kmFor);
        $completedKm = $this->completedKmInRange($user, $currentWeekStart, $today->copy()->subDay());
        $pinnedKm = $currentWeekSessions->filter(fn (PlannedSession $s): bool => $s->pinned)->sum($kmFor);

        $todayFixedKm = 0.0;
        if ($todaySession !== null && ! $todaySession->pinned) {
            $todayFixedKm = $clamp !== null ? $clamp['core_km'] : $kmFor($todaySession);
        }

        $eligibleDaysKm = [];
        foreach ($currentWeekSessions as $s) {
            if ($s->pinned || ! $s->date->isAfter($today)) {
                continue;
            }
            $eligibleDaysKm[$s->date->toDateString()] = $kmFor($s);
        }

        $remainingTargetKm = max(0.0, $weekTargetKm - $completedKm - $pinnedKm - $todayFixedKm);

        return VolumeRedistributor::redistribute($eligibleDaysKm, $remainingTargetKm);
    }

    private function completedKmInRange(User $user, Carbon $from, Carbon $to): float
    {
        if ($to->lessThan($from)) {
            return 0.0;
        }

        $meters = Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->whereBetween('activity_details.start_date_local', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('activity_details.distance');

        return DistanceFormatter::km((float) $meters);
    }
}
