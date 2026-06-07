<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\PrCategory;
use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared gamification stats for a user. Queried once and consumed by both
 * GoalResolver and UnlockEngine so the ~10 DB queries are not duplicated
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

        $streakWeeks = self::consecutiveWeekStreak($user);
        $twoWeekStreak = min($streakWeeks, 2);

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
            ->whereHas('detail', fn ($q) => $q->where('distance', '>=', PrCategory::HalfMarathon->distanceMeters()))
            ->count();

        $fastPace = Activity::query()
            ->where('user_id', $user->id)
            ->whereHas('detail', fn ($q) => $q->where('average_speed', '>=', self::FAST_PACE_SPEED_MS))
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

    /**
     * Counts the run of weeks (each with runs > 0) that are consecutive,
     * walking back from the most recent `week_ending`. Weeks are consecutive
     * when their `week_ending` dates are exactly 7 days apart; the first gap
     * ends the streak. Non-adjacent weeks (e.g. weeks 1, 5, 9) yield a streak
     * of 1, not 3.
     */
    private static function consecutiveWeekStreak(User $user): int
    {
        /** @var list<Carbon> $weekEndings */
        $weekEndings = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('runs', '>', 0)
            ->orderByDesc('week_ending')
            ->pluck('week_ending')
            ->all();

        if ($weekEndings === []) {
            return 0;
        }

        $streak = 1;
        $previous = $weekEndings[0]->copy()->startOfDay();
        for ($i = 1, $count = count($weekEndings); $i < $count; $i++) {
            $current = $weekEndings[$i]->copy()->startOfDay();
            if (abs($current->diffInDays($previous)) !== 7.0) {
                break;
            }
            $streak++;
            $previous = $current;
        }

        return $streak;
    }
}
