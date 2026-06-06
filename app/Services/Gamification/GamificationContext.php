<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Shared gamification stats for a user. Queried once and consumed by both
 * GoalResolver and UnlockEngine so the ~10 DB queries are not duplicated
 * across the two services.
 */
readonly class GamificationContext
{
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

    public static function forUser(User $user): self
    {
        $prCount = PersonalRecord::query()->where('user_id', $user->id)->count();
        $activityCount = Activity::query()->where('user_id', $user->id)->count();

        $rarityCounts = RunCard::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->select('rarity', DB::raw('COUNT(*) as cnt'))
            ->groupBy('rarity')
            ->pluck('cnt', 'rarity')
            ->all();

        $totalDistanceM = (float) Activity::query()
            ->where('user_id', $user->id)
            ->join('activity_details', 'activities.id', '=', 'activity_details.activity_id')
            ->sum('activity_details.distance');

        $streakWeeks = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('runs', '>', 0)
            ->orderByDesc('week_ending')
            ->limit(4)
            ->count();

        $twoWeekStreak = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('runs', '>', 0)
            ->orderByDesc('week_ending')
            ->limit(2)
            ->count();

        $tenKPlus = Activity::query()
            ->where('user_id', $user->id)
            ->whereHas('detail', fn ($q) => $q->where('distance', '>=', 10000))
            ->count();

        $fiveKPlus = Activity::query()
            ->where('user_id', $user->id)
            ->whereHas('detail', fn ($q) => $q->where('distance', '>=', 5000))
            ->count();

        $halfMarathon = Activity::query()
            ->where('user_id', $user->id)
            ->whereHas('detail', fn ($q) => $q->where('distance', '>=', 21000))
            ->count();

        $fastPace = Activity::query()
            ->where('user_id', $user->id)
            ->whereHas('detail', fn ($q) => $q->where('average_speed', '>=', 3.0))
            ->count();

        $badgeCounts = RunCard::badgeCountsForUser($user->id);

        return new self(
            user: $user,
            prCount: $prCount,
            activityCount: $activityCount,
            totalDistanceM: $totalDistanceM,
            rarityCounts: $rarityCounts,
            streakWeeks: $streakWeeks,
            twoWeekStreak: $twoWeekStreak,
            tenKPlus: $tenKPlus,
            fiveKPlus: $fiveKPlus,
            halfMarathon: $halfMarathon,
            fastPace: $fastPace,
            badgeCounts: $badgeCounts,
        );
    }
}
