<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use NoDiscard;
use App\Enums\PrCategory;
use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Shared gamification stats for a user. Queried once and consumed by both
 * GoalResolver and UnlockEngine so the six DB queries are not duplicated
 * across the two services.
 */
readonly class GamificationContext
{
    /**
     * Threshold for the "sepatu_cepat" goal: average pace under 5:30/km.
     * 5:30/km = 330 s/km, so an average_speed of 1000/330 m/s (~3.0303)
     * is exactly 5:30/km. A run qualifies when it is faster than that.
     */
    private const float FAST_PACE_SPEED_MS = 1000 / 330;

    /**
     * @param  array<string, int>  $rarityCounts
     * @param  array<string, int>  $badgeCounts
     */
    public function __construct(
        public User $user,
        public int $prCount,
        public int $activityCount,
        public float $totalDistanceM,
        public array $rarityCounts,
        public int $streakWeeks,
        public int $twoWeekStreak,
        public int $tenKPlus,
        public int $fiveKPlus,
        public int $halfMarathon,
        public int $fastPace,
        public array $badgeCounts,
    ) {
    }

    public function totalDistanceKm(): float
    {
        return round($this->totalDistanceM / 1000, 1);
    }

    #[NoDiscard]
    public static function forUser(User $user): self
    {
        $prCount = PersonalRecord::query()->where('user_id', $user->id)->count();
        // Stubs are excluded by the AnalyzedScope, so this counts only ingested
        // runs toward goals/unlocks (1 / 10 / 50 runs).
        $activityCount = Activity::query()->where('user_id', $user->id)->count();

        $rarityCounts = RunCard::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->select('rarity', DB::raw('COUNT(*) as cnt'))
            ->groupBy('rarity')
            ->pluck('cnt', 'rarity')
            ->all();

        $detail = self::detailAggregates($user);

        $streakWeeks = WeeklySnapshot::consecutiveWeekStreak($user->id);
        $twoWeekStreak = min($streakWeeks, 2);

        $badgeCounts = RunCard::badgeCountsForUser($user->id);

        return new self(
            user: $user,
            prCount: $prCount,
            activityCount: $activityCount,
            totalDistanceM: $detail['total_distance_m'],
            rarityCounts: $rarityCounts,
            streakWeeks: $streakWeeks,
            twoWeekStreak: $twoWeekStreak,
            tenKPlus: $detail['ten_k_plus'],
            fiveKPlus: $detail['five_k_plus'],
            halfMarathon: $detail['half_marathon'],
            fastPace: $detail['fast_pace'],
            badgeCounts: $badgeCounts,
        );
    }

    /**
     * Total distance plus the four distance/pace milestone counters in one pass.
     * `activity_details.activity_id` is UNIQUE, so this inner join matches each
     * activity at most once and the conditional SUMs are exactly the counts the
     * four `whereHas('detail', ...)` subqueries returned.
     *
     * @return array{total_distance_m: float, five_k_plus: int, ten_k_plus: int, half_marathon: int, fast_pace: int}
     */
    private static function detailAggregates(User $user): array
    {
        $row = Activity::query()
            ->where('user_id', $user->id)
            ->join('activity_details', 'activities.id', '=', 'activity_details.activity_id')
            ->selectRaw(
                'SUM(activity_details.distance) AS total_distance, '
                .'SUM(CASE WHEN activity_details.distance >= ? THEN 1 ELSE 0 END) AS five_k_plus, '
                .'SUM(CASE WHEN activity_details.distance >= ? THEN 1 ELSE 0 END) AS ten_k_plus, '
                .'SUM(CASE WHEN activity_details.distance >= ? THEN 1 ELSE 0 END) AS half_marathon, '
                .'SUM(CASE WHEN activity_details.average_speed >= ? THEN 1 ELSE 0 END) AS fast_pace',
                [5000, 10000, PrCategory::HalfMarathon->distanceMeters(), self::FAST_PACE_SPEED_MS],
            )
            ->first();

        return [
            'total_distance_m' => (float) ($row?->getAttribute('total_distance') ?? 0),
            'five_k_plus' => (int) ($row?->getAttribute('five_k_plus') ?? 0),
            'ten_k_plus' => (int) ($row?->getAttribute('ten_k_plus') ?? 0),
            'half_marathon' => (int) ($row?->getAttribute('half_marathon') ?? 0),
            'fast_pace' => (int) ($row?->getAttribute('fast_pace') ?? 0),
        ];
    }
}
