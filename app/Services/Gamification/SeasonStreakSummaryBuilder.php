<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Actions\Gamification\SettleStreakRestTokensAction;
use App\Models\Season;
use App\Models\StreakRestToken;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

/**
 * Builds the season and streak read models shared by the Plan tab and the
 * Profile page. Purely a read: {@see \App\Http\Controllers\PlanController}
 * passes it the {@see Season} its own {@see
 * \App\Services\Run\Plan\SeasonService::ensureCurrent()} call already
 * created, while {@see \App\Http\Controllers\ProfileController} passes
 * whatever {@see \App\Services\Run\Plan\SeasonService::peekCurrent()} finds
 * (possibly `null`) — a second page load must never trigger season creation
 * or badge-board grants on its own.
 */
final readonly class SeasonStreakSummaryBuilder
{
    public function __construct(
        private SeasonGoalResolver $seasonGoalResolver,
        private TrainingLoad $trainingLoad,
    ) {
    }

    /**
     * @param  SeasonGamificationContext|null  $context  Pass a pre-built context (e.g. one the caller already computed for {@see \App\Actions\Gamification\GrantSeasonUnlocksAction}) to avoid resolving it twice.
     * @return array{starts_at: string, ends_at: string, week_index: int, total_weeks: int, is_race_oriented: bool, tiers_kept_from_past_seasons: int, goals: list<array{id: int, title: string, current: int|float, target: int|float, unit: string, is_completed: bool}>}|null
     */
    public function seasonPayload(User $user, ?Season $season, Carbon $today, ?SeasonGamificationContext $context = null): ?array
    {
        if ($season === null) {
            return null;
        }

        $context ??= SeasonGamificationContext::forSeason($user, $season, $today, $this->trainingLoad);
        $goals = $this->seasonGoalResolver->forSeason($user, $season, $context);

        $totalWeeks = max(1, (int) $season->starts_at->diffInWeeks($season->ends_at) + 1);
        $weekIndex = max(1, min($totalWeeks, (int) $season->starts_at->diffInWeeks($today) + 1));

        return [
            'starts_at' => $season->starts_at->toDateString(),
            'ends_at' => $season->ends_at->toDateString(),
            'week_index' => $weekIndex,
            'total_weeks' => $totalWeeks,
            'is_race_oriented' => $season->race_goal_id !== null,
            'tiers_kept_from_past_seasons' => $this->tiersKeptFromPastSeasons($user, $season),
            'goals' => $goals,
        ];
    }

    /**
     * The weekly streak, its stakes for the open week, and the rest weeks that
     * stand between a runless week and a reset. Spending is automatic at week
     * close ({@see SettleStreakRestTokensAction}), so nothing here is an
     * affordance the user could act on.
     *
     * @return array{weeks: int, rest_weeks_held: int, rest_weeks_cap: int, weeks_to_next_rest_week: int|null, ran_this_week: bool, week_ends_on: string, last_forgiven_week: string|null}
     */
    public function streakPayload(User $user, Carbon $today): array
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
}
