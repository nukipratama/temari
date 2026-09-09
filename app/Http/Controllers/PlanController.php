<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Http\Requests\UpdatePlannedSessionRequest;
use App\Models\ActivityDetail;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\User;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Gamification\SeasonStreakSummaryBuilder;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\CurrentWeekPlanBuilder;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanRenderer;
use App\Services\Run\Plan\ReadinessClamp;
use App\Services\Run\Plan\SeasonService;
use App\Services\Run\Plan\SeasonSummaryBuilder;
use App\Services\Run\Plan\SegmentGenerator;
use App\Services\Run\Plan\SessionMatcher;
use App\Services\Run\Plan\SessionSegment;
use App\Services\Run\Plan\TrainingBaseline;
use App\Services\Run\Plan\VolumeRedistributor;
use App\Services\Run\Story\BriefingContext;
use App\Support\TrainingDisclaimer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

/**
 * Serves the Plan tab: the current week (plus lookahead) of a user's
 * periodized plan, with the readiness clamp and volume redistribution
 * applied at render time only — the stored {@see PlannedSession} rows are
 * never mutated by a page load. See `docs/features/plan-periodizer.md`.
 */
class PlanController extends Controller
{
    private const int LOOKAHEAD_WEEKS = 4;

    public function index(
        Request $request,
        TrainingBaseline $baseline,
        TrainingLoad $trainingLoad,
        VdotEstimator $vdotEstimator,
        TrainingPaceCalculator $paceCalculator,
        SeasonService $seasonService,
        SeasonStreakSummaryBuilder $seasonStreakBuilder,
        SeasonSummaryBuilder $seasonSummaryBuilder,
        SessionMatcher $sessionMatcher,
        PlanNarrationRequester $narrationRequester,
        ResolveActiveRaceAction $activeRace,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today();
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);

        // Two requests serve this page: the initial render, then Inertia's
        // partial for the deferred props — and the whole action runs again on
        // the second one. Every prop is a closure so the partial resolves only
        // what it asked for; the season is memoized because three props want it
        // and `ensureCurrent()` writes.
        $season = null;
        $loadSeason = function () use (&$season, $user, $today, $seasonService): Season {
            if ($season === null) {
                $season = $seasonService->ensureCurrent($user, $today);
            }

            return $season;
        };

        return Inertia::render('Plan', [
            'race' => fn (): ?array => $this->racePayload($activeRace($user->id)),
            'sessionsPerWeek' => fn (): int => $baseline->forUser($user, $today)['sessions_per_week'],
            'weeks' => Inertia::defer(fn (): array => $this->weeksPayload(
                $user,
                $today,
                $activeRace($user->id),
                $baseline,
                $trainingLoad,
                $vdotEstimator,
                $paceCalculator,
                $sessionMatcher,
                $narrationRequester,
            )),
            'season' => fn (): ?array => $seasonStreakBuilder->seasonPayload($user, $loadSeason(), $today),
            'seasonSummary' => Inertia::defer(fn (): array => $seasonSummaryBuilder->build($user, $loadSeason(), $today)),
            'seasonAdherencePct' => Inertia::defer(fn (): ?int => $seasonSummaryBuilder->adherencePct($user, $loadSeason())),
            'adaptation' => Inertia::defer(fn (): ?array => $this->adaptationPayload($user, $currentWeekStart)),
            'disclaimerHeadline' => TrainingDisclaimer::HEADLINE,
            'disclaimer' => TrainingDisclaimer::TEXT,
            'planNarration' => Inertia::defer(function () use ($narrationRequester, $user, $today): array {
                // Demo is excluded from plan:regenerate's real narration dispatch (no
                // LLM billing for the public account), so its Plan page fills any gap
                // with the same rule-based path its manual "Reread" already resolves
                // through — otherwise the demo would show perpetually-Pending blocks.
                if ($user->is_demo) {
                    $narrationRequester->ensureDemoFilled($user, $today);
                }

                return $narrationRequester->payloadsForCurrentWeek($user, $today);
            }),
            'regenerateCooldownSeconds' => fn (): ?int => $narrationRequester->regenerateCooldownRemaining($user),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function weeksPayload(
        User $user,
        Carbon $today,
        ?RaceGoal $race,
        TrainingBaseline $baseline,
        TrainingLoad $trainingLoad,
        VdotEstimator $vdotEstimator,
        TrainingPaceCalculator $paceCalculator,
        SessionMatcher $sessionMatcher,
        PlanNarrationRequester $narrationRequester,
    ): array {
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $rangeStart = $currentWeekStart->copy()->subWeeks(CurrentWeekPlanBuilder::HISTORY_WEEKS);
        $rangeEnd = $currentWeekStart->copy()->addWeeks(self::LOOKAHEAD_WEEKS)->addDays(6);

        $sessions = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->orderBy('date')
            ->get();


        $baselineData = $baseline->forUser($user, $today);
        $paces = $paceCalculator->fromVdotResult($vdotEstimator->estimate($user));
        $ceiling = ReadinessCeiling::from(
            BriefingContext::forUser($user, $today, $trainingLoad->summary($user, $today))->readinessCeiling,
        );
        $raceDistanceM = $race !== null ? (float) $race->distance_m : null;

        $sessionsByWeek = $sessions->groupBy(
            fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
        );

        [$phaseByWeek, $multiplierByWeek] = PlanRenderer::weekPhasesAndMultipliers($sessionsByWeek);
        $primaryEasyDateByWeek = $sessionsByWeek->map(fn (Collection $weekSessions): ?string => PlanRenderer::primaryEasyDate($weekSessions));

        $currentWeekKey = $currentWeekStart->toDateString();

        // Every past row should already carry its real status —
        // plan:score-compliance (daily) persists it the morning after. This
        // is the safety net for whatever it hasn't reached yet, plus today,
        // which it deliberately never reaches; both are a small subset, so
        // it's computed for those dates rather than the whole range.
        $staleSessions = $sessions->filter(
            fn (PlannedSession $s): bool => $s->status === PlannedSessionStatus::Planned && $s->date->lte($today),
        );
        $fallbackStatuses = [];
        if ($staleSessions->isNotEmpty()) {
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
            $fallbackStatuses = $sessionMatcher->statuses($user, $stalePlannedKm, $staleExcused, $today);
        }

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
        $clampVoice = $clamp === null ? null : $narrationRequester->clampVoiceFor($user, $today);

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

        $activityByDate = $sessionMatcher->activityByDate($user, $rangeStart, $today);

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

    public function regenerate(Request $request, Periodizer $periodizer, PlanNarrationRequester $narrationRequester): RedirectResponse
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::User);

        /** @var User $user */
        $user = $request->user();

        if ($narrationRequester->regenerateCooldownRemaining($user) !== null) {
            return back()->with('info', "Temari's still catching up on the last replan. Give it a little longer.");
        }

        $periodizer->regenerate($user);
        $narrationRequester->requestForCurrentWeek($user, Carbon::today());
        $narrationRequester->startRegenerateCooldown($user);

        return back()->with('success', "Temari's replanned the weeks ahead against where you are now.");
    }

    /**
     * Move (date), block (session_type = rest), skip (excuse the day before
     * it passes), or pin/unpin. Any explicit edit fixes the day (pins it) so
     * the next regeneration doesn't silently overwrite it, unless the caller
     * passes `pinned: false` to hand control back to the periodizer.
     *
     * A move onto a date the athlete already has a row for is a **swap**, not
     * a re-date: `planned_sessions` is unique on (user_id, date) and the
     * periodizer materializes all seven days of every week, so every in-horizon
     * target is occupied.
     */
    public function update(UpdatePlannedSessionRequest $request, PlannedSession $plannedSession, PlanNarrationRequester $narrationRequester): RedirectResponse
    {
        $this->authorizeOwner($request, $plannedSession);

        app(NarrationOrigin::class)->set(AnalysisOrigin::User);

        $attributes = $request->validated();
        if (! array_key_exists('pinned', $attributes)) {
            $attributes['pinned'] = true;
        }

        $today = Carbon::today();
        $touchedDates = [$plannedSession->date];

        $occupant = $this->occupantOfMoveTarget($plannedSession, $attributes['date'] ?? null);
        if ($occupant !== null) {
            $touchedDates[] = $occupant->date;
            $this->swapSessions($plannedSession, $occupant);
            unset($attributes['date']);
        }

        $plannedSession->update($attributes);

        // Keep the day's narration in sync with the edit — otherwise it keeps
        // describing whatever was prescribed before the skip/block/move.
        // Only within the current week: that's the only window day narration
        // is ever requested for in the first place.
        if ($occupant !== null || $plannedSession->wasChanged(['session_type', 'skipped', 'date'])) {
            foreach ($touchedDates as $date) {
                if ($narrationRequester->isWithinCurrentWeek($date, $today)) {
                    $narrationRequester->requestDayNarration($plannedSession->user_id, $date);
                }
            }
        }

        return back();
    }

    private function occupantOfMoveTarget(PlannedSession $plannedSession, ?string $toDate): ?PlannedSession
    {
        if ($toDate === null || Carbon::parse($toDate)->isSameDay($plannedSession->date)) {
            return null;
        }

        return PlannedSession::query()
            ->where('user_id', $plannedSession->user_id)
            ->whereDate('date', $toDate)
            ->first();
    }

    /**
     * Trades what the two days prescribe while each keeps its own calendar
     * slot, and pins both so the next regeneration honours the choice.
     */
    private function swapSessions(PlannedSession $from, PlannedSession $to): void
    {
        DB::transaction(function () use ($from, $to): void {
            [$fromType, $toType] = [$from->session_type, $to->session_type];
            [$fromSkipped, $toSkipped] = [$from->skipped, $to->skipped];

            $to->update(['session_type' => $fromType, 'skipped' => $fromSkipped, 'pinned' => true]);
            $from->update(['session_type' => $toType, 'skipped' => $toSkipped, 'pinned' => true]);
        });
    }

    private function authorizeOwner(Request $request, PlannedSession $plannedSession): void
    {
        /** @var User $user */
        $user = $request->user();
        if ($plannedSession->user_id !== $user->id) {
            abort(403);
        }
    }

    /**
     * @return array{race_date: string, name: string|null}|null
     */
    private function racePayload(?RaceGoal $race): ?array
    {
        return $race === null ? null : ['race_date' => $race->race_date->toDateString(), 'name' => $race->name];
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


    /**
     * @return array{reason: string, headline: string, detail: string, deload: bool}|null
     */
    private function adaptationPayload(User $user, Carbon $currentWeekStart): ?array
    {
        $adaptation = PlanAdaptation::query()
            ->where('user_id', $user->id)
            ->where('week_start', $currentWeekStart->toDateString())
            ->first();

        return $adaptation === null ? null : [
            'reason' => $adaptation->reason->value,
            'headline' => $adaptation->reason->headline(),
            'detail' => $adaptation->reason->detail($adaptation->adherence_pct),
            'deload' => $adaptation->deload,
        ];
    }

    private function completedKmInRange(User $user, Carbon $from, Carbon $to): float
    {
        if ($to->lessThan($from)) {
            return 0.0;
        }

        $meters = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->whereBetween('activity_details.start_date_local', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('activity_details.distance');

        return DistanceFormatter::km((float) $meters);
    }
}
