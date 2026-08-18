<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Gamification\GrantSeasonUnlocksAction;
use App\Actions\Gamification\SettleStreakRestTokensAction;
use App\Enums\DistanceBand;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Http\Requests\UpdatePlannedSessionRequest;
use App\Models\ActivityDetail;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\StreakRestToken;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Services\Gamification\SeasonGamificationContext;
use App\Services\Gamification\SeasonGoalResolver;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\DistanceBandKm;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanRenderer;
use App\Services\Run\Plan\ReadinessClamp;
use App\Services\Run\Plan\SeasonService;
use App\Services\Run\Plan\SessionMatcher;
use App\Services\Run\Plan\TrainingBaseline;
use App\Services\Run\Plan\VolumeRedistributor;
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
        SeasonGoalResolver $seasonGoalResolver,
        GrantSeasonUnlocksAction $grantSeasonUnlocks,
        SessionMatcher $sessionMatcher,
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
        $seasonPayload = $this->seasonPayload(
            $season,
            $seasonGoalResolver->forSeason($user, $season, $seasonCtx),
            $today,
            $this->tiersKeptFromPastSeasons($user, $season),
        );
        $streakPayload = $this->streakPayload($user, $today);

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
                'streak' => $streakPayload,
                'adaptation' => $adaptationPayload,
                'disclaimerHeadline' => TrainingDisclaimer::HEADLINE,
                'disclaimer' => TrainingDisclaimer::TEXT,
            ]);
        }

        $baselineData = $baseline->forUser($user, $today);
        $paces = $paceCalculator->fromVdotResult($vdotEstimator->estimate($user));
        $ceiling = ReadinessCeiling::from(
            BriefingContext::forUser($user, $today, $trainingLoad->summary($user, $today))->readinessCeiling,
        );

        $sessionsByWeek = $sessions->groupBy(
            fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
        );

        [$phaseByWeek, $multiplierByWeek] = PlanRenderer::weekPhasesAndMultipliers($sessionsByWeek);

        $currentWeekKey = $currentWeekStart->toDateString();
        $currentWeekMultiplier = $multiplierByWeek[$currentWeekKey] ?? 1.0;
        $bandKmThisWeek = $this->bandKmFor($baselineData['long_run_km'], $currentWeekMultiplier);

        $plannedKmByDate = [];
        foreach ($sessions as $s) {
            $weekKey = $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
            $plannedKmByDate[$s->date->toDateString()] = DistanceBandKm::kmFor(
                $s->distance_band,
                $baselineData['long_run_km'],
                $multiplierByWeek[$weekKey] ?? 1.0,
            );
        }
        $statuses = $sessionMatcher->statuses($user, $plannedKmByDate, $today);

        // Readiness clamp: TODAY's row only — a future day's readiness isn't
        // knowable today, so clamping never reaches past this one row.
        $todaySession = $sessions->first(fn (PlannedSession $s): bool => $s->date->isSameDay($today));
        $clamp = ($todaySession !== null && ! $todaySession->pinned)
            ? ReadinessClamp::apply($todaySession->session_type, $todaySession->distance_band, $ceiling)
            : null;

        $redistributed = $this->redistributeCurrentWeek(
            $user,
            $sessionsByWeek->get($currentWeekKey, collect()),
            $today,
            $currentWeekStart,
            $bandKmThisWeek,
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

            $weeks[] = [
                'week_start' => $weekStartKey,
                'phase' => $weekPhase->value,
                'type' => $weekStartKey < $currentWeekKey ? 'history' : ($weekStartKey === $currentWeekKey ? 'current' : 'lookahead'),
                'days' => $weekSessions->map(fn (PlannedSession $s) => PlanRenderer::dayPayload(
                    $s,
                    $today,
                    $clamp,
                    $redistributed,
                    $baselineData['long_run_km'],
                    $multiplierByWeek[$weekStartKey] ?? 1.0,
                    $paces,
                    $statuses[$s->date->toDateString()] ?? PlannedSessionStatus::Planned,
                ))->all(),
            ];
        }

        return Inertia::render('Plan', [
            'race' => $this->racePayload($race),
            'sessionsPerWeek' => $baselineData['sessions_per_week'],
            'weeks' => $weeks,
            'season' => $seasonPayload,
            'streak' => $streakPayload,
            'adaptation' => $adaptationPayload,
            'disclaimerHeadline' => TrainingDisclaimer::HEADLINE,
            'disclaimer' => TrainingDisclaimer::TEXT,
        ]);
    }

    public function regenerate(Request $request, Periodizer $periodizer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $periodizer->regenerate($user);

        return back()->with('success', "Temari's replanned the weeks ahead against where you are now.");
    }

    /**
     * Move (date), resize (distance_band), block (session_type = rest), or
     * pin/unpin. Any explicit edit fixes the day (pins it) so the next
     * regeneration doesn't silently overwrite it, unless the caller passes
     * `pinned: false` to hand control back to the periodizer.
     */
    public function update(UpdatePlannedSessionRequest $request, PlannedSession $plannedSession): RedirectResponse
    {
        $this->authorizeOwner($request, $plannedSession);

        $attributes = $request->validated();
        if (! array_key_exists('pinned', $attributes)) {
            $attributes['pinned'] = true;
        }
        // Blocking a day (session_type = rest) always clears its distance/pace,
        // keeping the invariant "pace_band is null exactly when session_type is
        // rest" regardless of what else the request sent.
        if (($attributes['session_type'] ?? null) === SessionType::Rest->value) {
            $attributes['distance_band'] = DistanceBand::Rest->value;
            $attributes['pace_band'] = null;
        }

        $plannedSession->update($attributes);

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
     * The Plan tab's season summary: arc progress + the season's 5 goals +
     * a link to the badge board. Season IS the training block (see the v2
     * program's locked decisions) — this is the same arc at a higher zoom,
     * not a separate page.
     *
     * @param  list<array{id: int, title: string, current: int|float, target: int|float, unit: string, is_completed: bool}>  $goals
     * @return array{starts_at: string, ends_at: string, week_index: int, total_weeks: int, is_race_oriented: bool, tiers_kept_from_past_seasons: int, goals: list<array{id: int, title: string, current: int|float, target: int|float, unit: string, is_completed: bool}>}
     */
    private function seasonPayload(Season $season, array $goals, Carbon $today, int $tiersKeptFromPastSeasons): array
    {
        $totalWeeks = max(1, (int) $season->starts_at->diffInWeeks($season->ends_at) + 1);
        $weekIndex = max(1, min($totalWeeks, (int) $season->starts_at->diffInWeeks($today) + 1));

        return [
            'starts_at' => $season->starts_at->toDateString(),
            'ends_at' => $season->ends_at->toDateString(),
            'week_index' => $weekIndex,
            'total_weeks' => $totalWeeks,
            'is_race_oriented' => $season->race_goal_id !== null,
            'tiers_kept_from_past_seasons' => $tiersKeptFromPastSeasons,
            'goals' => $goals,
        ];
    }

    /**
     * Track tiers owned under an earlier season's key namespace. A season
     * boundary resets the live track to zero and revokes nothing, so this is
     * the number that proves it.
     */
    private function tiersKeptFromPastSeasons(User $user, Season $season): int
    {
        return UserUnlock::query()
            ->where('user_id', $user->id)
            ->where('unlock_key', 'like', 'season.%.track\_%')
            ->where('unlock_key', 'not like', "season.{$season->id}.%")
            ->count();
    }

    /**
     * The weekly streak, its stakes for the open week, and the rest weeks that
     * stand between a runless week and a reset. Spending is automatic at week
     * close ({@see SettleStreakRestTokensAction}), so nothing here is an
     * affordance the user could act on.
     *
     * @return array{weeks: int, rest_weeks_held: int, rest_weeks_cap: int, weeks_to_next_rest_week: int|null, ran_this_week: bool, week_ends_on: string, last_forgiven_week: string|null}
     */
    private function streakPayload(User $user, Carbon $today): array
    {
        $weeks = WeeklySnapshot::consecutiveWeekStreak($user->id);
        $held = StreakRestToken::unspentCountForUser($user->id);
        $weekEndsOn = $today->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();

        $accrual = SettleStreakRestTokensAction::ACCRUAL_EVERY_WEEKS;
        $atCap = $held >= SettleStreakRestTokensAction::MAX_HELD;

        $lastForgiven = StreakRestToken::query()
            ->where('user_id', $user->id)
            ->whereNotNull('spent_for_week_ending')
            ->orderByDesc('spent_for_week_ending')
            ->first();

        return [
            'weeks' => $weeks,
            'rest_weeks_held' => $held,
            'rest_weeks_cap' => SettleStreakRestTokensAction::MAX_HELD,
            'weeks_to_next_rest_week' => $atCap ? null : $accrual - ($weeks % $accrual),
            'ran_this_week' => WeeklySnapshot::query()
                ->where('user_id', $user->id)
                ->where('week_ending', $weekEndsOn->toDateString())
                ->where('runs', '>', 0)
                ->exists(),
            'week_ends_on' => $weekEndsOn->toDateString(),
            'last_forgiven_week' => $lastForgiven?->spent_for_week_ending?->toDateString(),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function bandKmFor(float $longRunKm, float $multiplier): array
    {
        return [
            DistanceBand::Short->value => DistanceBandKm::kmFor(DistanceBand::Short, $longRunKm, $multiplier),
            DistanceBand::Medium->value => DistanceBandKm::kmFor(DistanceBand::Medium, $longRunKm, $multiplier),
            DistanceBand::Long->value => DistanceBandKm::kmFor(DistanceBand::Long, $longRunKm, $multiplier),
        ];
    }

    /**
     * @param  Collection<int, PlannedSession>  $currentWeekSessions
     * @param  array<string, float>  $bandKmThisWeek
     * @param  array{session_type: SessionType, distance_band: DistanceBand, pace_band: mixed, note: string}|null  $clamp
     * @return array<string, DistanceBand>
     */
    private function redistributeCurrentWeek(
        User $user,
        Collection $currentWeekSessions,
        Carbon $today,
        Carbon $currentWeekStart,
        array $bandKmThisWeek,
        ?PlannedSession $todaySession,
        ?array $clamp,
    ): array {
        if ($currentWeekSessions->isEmpty()) {
            return [];
        }

        $weekTargetKm = $currentWeekSessions->sum(fn (PlannedSession $s) => $bandKmThisWeek[$s->distance_band->value] ?? 0.0);
        $completedKm = $this->completedKmInRange($user, $currentWeekStart, $today->copy()->subDay());
        $pinnedKm = $currentWeekSessions
            ->filter(fn (PlannedSession $s): bool => $s->pinned)
            ->sum(fn (PlannedSession $s) => $bandKmThisWeek[$s->distance_band->value] ?? 0.0);

        $todayFixedKm = 0.0;
        if ($todaySession !== null && ! $todaySession->pinned) {
            $todayBand = $clamp['distance_band'] ?? $todaySession->distance_band;
            $todayFixedKm = $bandKmThisWeek[$todayBand->value] ?? 0.0;
        }

        $eligibleDays = [];
        foreach ($currentWeekSessions as $s) {
            if ($s->pinned || ! $s->date->isAfter($today)) {
                continue;
            }
            $eligibleDays[$s->date->toDateString()] = $s->distance_band;
        }

        $remainingTargetKm = max(0.0, $weekTargetKm - $completedKm - $pinnedKm - $todayFixedKm);

        return VolumeRedistributor::redistribute($eligibleDays, $remainingTargetKm, $bandKmThisWeek);
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
