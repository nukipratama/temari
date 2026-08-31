<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Gamification\GrantSeasonUnlocksAction;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Http\Requests\UpdatePlannedSessionRequest;
use App\Models\ActivityDetail;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Gamification\SeasonGamificationContext;
use App\Services\Gamification\SeasonStreakSummaryBuilder;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
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
use App\Services\Run\Plan\WeekPlanBuilder;
use App\Services\Run\Story\BriefingContext;
use App\Support\TrainingDisclaimer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * Serves the Plan tab: the current week (plus lookahead) of a user's
 * periodized plan, with the readiness clamp and volume redistribution
 * applied at render time only — the stored {@see PlannedSession} rows are
 * never mutated by a page load. See `docs/features/plan-periodizer.md`.
 */
class PlanController extends Controller
{
    private const int HISTORY_WEEKS = 3;

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
        GrantSeasonUnlocksAction $grantSeasonUnlocks,
        SessionMatcher $sessionMatcher,
        PlanNarrationRequester $narrationRequester,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today();
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $rangeStart = $currentWeekStart->copy()->subWeeks(self::HISTORY_WEEKS);
        $rangeEnd = $currentWeekStart->copy()->addWeeks(self::LOOKAHEAD_WEEKS)->addDays(6);

        $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();

        $season = $seasonService->ensureCurrent($user, $today);
        $seasonCtx = SeasonGamificationContext::forSeason($user, $season, $today, $trainingLoad);
        $grantSeasonUnlocks($user, $season, $seasonCtx);

        // Demo is excluded from plan:regenerate's real narration dispatch (no
        // LLM billing for the public account), so its Plan page fills any gap
        // with the same rule-based path its manual "Reread" already resolves
        // through — otherwise the demo would show perpetually-Pending blocks.
        if ($user->is_demo) {
            $narrationRequester->ensureDemoFilled($user, $today);
        }
        $seasonPayload = $seasonStreakBuilder->seasonPayload($user, $season, $today, $seasonCtx);
        $seasonSummary = $seasonSummaryBuilder->build($user, $season, $today);

        $adaptationPayload = $this->adaptationPayload($user, $currentWeekStart);

        $sessions = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->orderBy('date')
            ->get();

        if ($sessions->isEmpty()) {
            return Inertia::render('Plan', [
                'race' => $this->racePayload($race),
                'sessionsPerWeek' => $baseline->forUser($user, $today)['sessions_per_week'],
                'weeks' => [],
                'season' => $seasonPayload,
                'seasonSummary' => $seasonSummary,
                'adaptation' => $adaptationPayload,
                'disclaimerHeadline' => TrainingDisclaimer::HEADLINE,
                'disclaimer' => TrainingDisclaimer::TEXT,
                'planNarration' => $narrationRequester->payloadsForCurrentWeek($user, $today),
                'regenerateCooldownSeconds' => $narrationRequester->regenerateCooldownRemaining($user),
            ]);
        }

        $baselineData = $baseline->forUser($user, $today);
        $paces = $paceCalculator->fromVdotResult($vdotEstimator->estimate($user));
        $ceiling = ReadinessCeiling::from(
            BriefingContext::forUser($user, $today, $trainingLoad->summary($user, $today))->readinessCeiling,
        );
        $isMarathonDistance = WeekPlanBuilder::isMarathonDistance($race !== null ? (float) $race->distance_m : null);

        $sessionsByWeek = $sessions->groupBy(
            fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
        );

        [$phaseByWeek, $multiplierByWeek] = PlanRenderer::weekPhasesAndMultipliers($sessionsByWeek);
        $primaryEasyDateByWeek = $sessionsByWeek->map(fn (Collection $weekSessions): ?string => PlanRenderer::primaryEasyDate($weekSessions));

        $currentWeekKey = $currentWeekStart->toDateString();

        // Every past row should already carry its real status —
        // plan:score-compliance (daily) persists it the morning after. This
        // is only a safety net for whatever it hasn't reached yet, so it's
        // computed for just that (normally empty) subset, not the whole range.
        $staleSessions = $sessions->filter(
            fn (PlannedSession $s): bool => $s->status === PlannedSessionStatus::Planned && $s->date->lt($today),
        );
        $fallbackStatuses = [];
        if ($staleSessions->isNotEmpty()) {
            $stalePlannedKm = [];
            $staleSkipped = [];
            foreach ($staleSessions as $s) {
                $date = $s->date->toDateString();
                $weekKey = $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
                $stalePlannedKm[$date] = SegmentGenerator::coreKmFor(
                    $s->session_type,
                    $date === $primaryEasyDateByWeek->get($weekKey),
                    $baselineData['long_run_km'],
                    $multiplierByWeek[$weekKey] ?? 1.0,
                );
                $staleSkipped[$date] = $s->skipped;
            }
            $fallbackStatuses = $sessionMatcher->statuses($user, $stalePlannedKm, $staleSkipped, $today);
        }

        // Readiness clamp: TODAY's row only — a future day's readiness isn't
        // knowable today, so clamping never reaches past this one row.
        $todaySession = $sessions->first(fn (PlannedSession $s): bool => $s->date->isSameDay($today));
        $clamp = ($todaySession !== null && ! $todaySession->pinned)
            ? ReadinessClamp::apply(
                $todaySession->session_type,
                $todaySession->phase,
                $isMarathonDistance,
                $baselineData['long_run_km'],
                $multiplierByWeek[$currentWeekKey] ?? 1.0,
                $paces,
                $ceiling,
            )
            : null;

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
                    $isMarathonDistance,
                    $s->date->toDateString() === $primaryEasyDate,
                    $baselineData['long_run_km'],
                    $multiplierByWeek[$weekStartKey] ?? 1.0,
                    $paces,
                    $fallbackStatuses[$s->date->toDateString()] ?? $s->status,
                ))->all(),
            ];
        }

        return Inertia::render('Plan', [
            'race' => $this->racePayload($race),
            'sessionsPerWeek' => $baselineData['sessions_per_week'],
            'weeks' => $weeks,
            'season' => $seasonPayload,
            'seasonSummary' => $seasonSummary,
            'adaptation' => $adaptationPayload,
            'disclaimerHeadline' => TrainingDisclaimer::HEADLINE,
            'disclaimer' => TrainingDisclaimer::TEXT,
            'planNarration' => $narrationRequester->payloadsForCurrentWeek($user, $today),
            'regenerateCooldownSeconds' => $narrationRequester->regenerateCooldownRemaining($user),
        ]);
    }

    public function regenerate(Request $request, Periodizer $periodizer, PlanNarrationRequester $narrationRequester): RedirectResponse
    {
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
     */
    public function update(UpdatePlannedSessionRequest $request, PlannedSession $plannedSession, PlanNarrationRequester $narrationRequester): RedirectResponse
    {
        $this->authorizeOwner($request, $plannedSession);

        $attributes = $request->validated();
        if (! array_key_exists('pinned', $attributes)) {
            $attributes['pinned'] = true;
        }

        $plannedSession->update($attributes);

        // Keep the day's narration in sync with the edit — otherwise it keeps
        // describing whatever was prescribed before the skip/block/move.
        // Only within the current week: that's the only window day narration
        // is ever requested for in the first place.
        $today = Carbon::today();
        if ($plannedSession->wasChanged(['session_type', 'skipped', 'date'])
            && $narrationRequester->isWithinCurrentWeek($plannedSession->date, $today)) {
            $narrationRequester->requestDayNarration($plannedSession->user_id, $plannedSession->date);
        }

        return back();
    }

    public function destroy(Request $request, PlannedSession $plannedSession): RedirectResponse
    {
        $this->authorizeOwner($request, $plannedSession);
        $plannedSession->delete();

        return back();
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
