<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Services\Run\LifetimeStats;
use Illuminate\Support\Carbon;

/**
 * Everything the profile voice knows about a runner's whole history.
 */
final class LifetimeStatsTool extends UserTool
{
    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly LifetimeStats $lifetimeStats,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_lifetime_stats';
    }

    public function description(): string
    {
        return "The user's whole running history: name, total runs and km, longest run, how many "
            .'months they\'ve been running, PR count, accessories unlocked out of the total, weekly '
            ."streak, their favorite time to run, whether Strava's connected, and the latest "
            .'form_status. Start here.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        // The cached aggregate /calendar also reads, so the two surfaces cannot drift.
        $lifetime = $this->lifetimeStats->forUser($this->user);
        $firstRunAt = $lifetime['first_run_at'];

        return [
            'name' => $this->user->first_name ?? $this->user->name,
            'total_runs' => $lifetime['total_runs'],
            'total_km' => $lifetime['total_km'],
            'longest_run_km' => $lifetime['longest_km'],
            'months_running' => $firstRunAt !== null
                ? (int) Carbon::parse($firstRunAt)->diffInMonths($this->asOf)
                : null,
            'pr_count' => PersonalRecord::query()->where('user_id', $this->user->id)->count(),
            'unlocked_accessories' => UserUnlock::query()->where('user_id', $this->user->id)->count(),
            'total_accessories' => count(config('temari_unlocks', [])),
            'weekly_streak' => WeeklySnapshot::consecutiveWeekStreak($this->user->id),
            'favorite_time' => $this->favoriteTimeBucket(),
            'strava_connected' => $this->user->stravaConnection !== null,
            'form_status' => WeeklySnapshot::latestFormStatus($this->user->id),
        ];
    }

    /**
     * Which part of the day the runner runs in most often, or null when there
     * are no timestamped runs to count.
     */
    private function favoriteTimeBucket(): ?string
    {
        $byHour = ActivityDetail::query()
            ->whereHas('activity', fn ($query) => $query->where('user_id', $this->user->id))
            ->whereNotNull('start_date_local')
            ->selectRaw('HOUR(start_date_local) AS h, COUNT(*) AS c')
            ->groupBy('h')
            ->pluck('c', 'h');

        if ($byHour->isEmpty()) {
            return null;
        }

        $buckets = ['morning' => 0, 'midday' => 0, 'evening' => 0, 'night' => 0];
        foreach ($byHour as $hour => $count) {
            $buckets[self::timeBucket((int) $hour)] += (int) $count;
        }

        return array_keys($buckets, max($buckets))[0];
    }

    private static function timeBucket(int $hour): string
    {
        return match (true) {
            $hour >= 4 && $hour < 10 => 'morning',
            $hour >= 10 && $hour < 15 => 'midday',
            $hour >= 15 && $hour < 19 => 'evening',
            default => 'night',
        };
    }
}
