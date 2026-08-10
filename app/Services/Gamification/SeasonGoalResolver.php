<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Models\Season;
use App\Models\User;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Resolves a {@see Season}'s 5 {@see \App\Models\SeasonGoal} rows to live
 * `current` values, the same "read a `metric` string against a context"
 * pattern {@see GoalResolver::currentValue()} uses for the lifetime
 * accessory catalog — scoped to one season's {@see SeasonGamificationContext}
 * instead of the user's whole history.
 */
readonly class SeasonGoalResolver
{
    public function __construct(
        private TrainingLoad $trainingLoad,
    ) {
    }

    /**
     * @param  SeasonGamificationContext|null  $ctx  Pass a pre-built context (e.g. one the caller already computed for {@see \App\Actions\Gamification\GrantSeasonUnlocksAction}) to avoid resolving it twice.
     * @return list<array{id: int, title: string, current: int|float, target: int|float, unit: string, is_completed: bool}>
     */
    public function forSeason(User $user, Season $season, ?SeasonGamificationContext $ctx = null, ?Carbon $today = null): array
    {
        $ctx ??= SeasonGamificationContext::forSeason(
            $user,
            $season,
            ($today ?? Carbon::today())->copy()->startOfDay(),
            $this->trainingLoad,
        );

        $resolved = [];
        foreach ($season->goals as $goal) {
            $current = $this->currentValue($ctx, $goal->metric);

            $resolved[] = [
                'id' => $goal->id,
                'title' => $goal->title,
                'current' => min($current, $goal->target),
                'target' => $goal->target,
                'unit' => $goal->unit,
                'is_completed' => $current >= $goal->target,
            ];
        }

        return $resolved;
    }

    public function currentValue(SeasonGamificationContext $ctx, string $metric): int|float
    {
        return match ($metric) {
            'season_sessions_completed' => $ctx->sessionsCompleted,
            'season_quality_completed' => $ctx->qualityCompleted,
            'season_longest_long_run_km' => $ctx->longestLongRunKm,
            'season_rest_honored' => $ctx->restHonored,
            'season_race_goal_met' => $ctx->raceGoalMet ? 1 : 0,
            'season_ctl_growth' => $ctx->ctlGrowth,
            default => throw new InvalidArgumentException("Unknown season goal metric: {$metric}"),
        };
    }
}
