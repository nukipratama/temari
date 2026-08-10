<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use InvalidArgumentException;
use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Support\Facades\Cache;

/**
 * Computes goal progress for every unlock in the catalog. Each goal carries
 * its current progress, target value, and unit so the UI can render progress
 * bars without a dedicated DB table.
 *
 * @see config/temari_unlocks.php
 * @see config/temari_goals.php
 */
readonly class GoalResolver
{
    /**
     * Goal progress only moves when an activity is ingested, so the whole
     * resolved catalog is cached per user for a short window. The TTL matches
     * the goals-summary share in HandleInertiaRequests, so the nav chip and
     * `/goals` can never be more than one window apart from each other.
     */
    private const int CACHE_TTL_SECONDS = 120;

    /**
     * @param  GamificationContext|null  $ctx  A pre-built context to reuse; pass it when the caller already holds one to avoid re-running its six queries. Supplying one also bypasses the cache, since the caller has already decided which snapshot it wants read.
     * @return list<array{id: string, title: string, description: string, slot: string, rarity: string, current: int|float, target: int|float, unit: string, is_completed: bool}>
     */
    public function forUser(User $user, ?GamificationContext $ctx = null): array
    {
        if ($ctx !== null) {
            return $this->resolve($user, $ctx);
        }

        return Cache::remember(
            self::cacheKey($user->id),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->resolve($user, GamificationContext::forUser($user)),
        );
    }

    public static function cacheKey(int $userId): string
    {
        return "goals:{$userId}";
    }

    /**
     * @return list<array{id: string, title: string, description: string, slot: string, rarity: string, current: int|float, target: int|float, unit: string, is_completed: bool}>
     */
    private function resolve(User $user, GamificationContext $ctx): array
    {
        /** @var list<string> $unlockedKeys */
        $unlockedKeys = array_values(UserUnlock::query()
            ->where('user_id', $user->id)
            ->pluck('unlock_key')
            ->all());
        /** @var array<string, array{rarity?: string}> $unlocksCatalog */
        $unlocksCatalog = (array) config('temari_unlocks', []);
        /** @var array<string, array{title: string, description: string, slot: string, metric: string, metric_key?: string, target: int|float, unit: string}> $goalsCatalog */
        $goalsCatalog = (array) config('temari_goals', []);

        $goals = [];
        foreach ($goalsCatalog as $id => $goal) {
            $current = $this->currentValue($ctx, $goal['metric'], $goal['metric_key'] ?? '');

            $goals[] = [
                'id' => $id,
                'title' => $goal['title'],
                'description' => $goal['description'],
                'slot' => $goal['slot'],
                'rarity' => $this->rarityForKey($id, $unlocksCatalog),
                'current' => min($current, $goal['target']),
                'target' => $goal['target'],
                'unit' => $goal['unit'],
                'is_completed' => \in_array($id, $unlockedKeys, true),
            ];
        }

        return $goals;
    }

    /**
     * Resolves a `metric`/`metric_key` pair from the goal catalog against a
     * context. Shared with {@see \App\Actions\Gamification\GrantEligibleUnlocksAction},
     * which reads the same catalog to decide grant eligibility generically.
     */
    public function currentValue(GamificationContext $ctx, string $metric, string $metricKey): int|float
    {
        return match ($metric) {
            'pr_count' => $ctx->prCount,
            'activity_count' => $ctx->activityCount,
            'five_k_plus' => $ctx->fiveKPlus,
            'ten_k_plus' => $ctx->tenKPlus,
            'half_marathon' => $ctx->halfMarathon,
            'fast_pace' => $ctx->fastPace,
            'two_week_streak' => $ctx->twoWeekStreak,
            'total_distance_km' => $ctx->totalDistanceKm(),
            'rarity_count' => $ctx->rarityCounts[$metricKey] ?? 0,
            'badge_count' => $ctx->badgeCounts[$metricKey] ?? 0,
            default => throw new InvalidArgumentException("Unknown goal metric: {$metric}"),
        };
    }

    /**
     * @param  list<array{id: string, title: string, description: string, slot: string, rarity: string, current: int|float, target: int|float, unit: string, is_completed: bool}>  $goals
     */
    public function completedCount(array $goals): int
    {
        return count(array_filter($goals, fn (array $g): bool => $g['is_completed']));
    }

    /**
     * @param  list<array{id: string, title: string, description: string, slot: string, rarity: string, current: int|float, target: int|float, unit: string, is_completed: bool}>  $precomputedGoals  When the caller already has the goals array, pass it to avoid re-running gatherContext().
     * @return list<array{id: string, title: string, description: string, slot: string, rarity: string, current: int|float, target: int|float, unit: string, is_completed: bool}>
     */
    public function closestToCompletion(User $user, int $limit = 3, ?array $precomputedGoals = null): array
    {
        $goals = $precomputedGoals ?? $this->forUser($user);
        $incomplete = array_values(array_filter($goals, fn (array $g): bool => ! $g['is_completed']));

        $scored = array_map(function (array $goal): array {
            $pct = $goal['target'] > 0 ? $goal['current'] / $goal['target'] : 0;
            $capped = min($pct, 1.0);

            return ['goal' => $goal, 'pct' => $capped];
        }, $incomplete);

        usort($scored, fn (array $a, array $b): int => $b['pct'] <=> $a['pct']);

        return array_map(fn (array $s): array => $s['goal'], array_slice($scored, 0, $limit));
    }

    /**
     * @param  array<string, array{rarity?: string}>  $catalog
     */
    private function rarityForKey(string $key, array $catalog): string
    {
        return (string) ($catalog[$key]['rarity'] ?? 'common');
    }
}
