<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Support\Facades\Cache;

final class SchedulerChain
{
    public const string STREAK_SETTLE = 'streak:settle';

    public const string PLAN_CLOSE_FINISHED_RACES = 'plan:close-finished-races';

    public const string PLAN_SCORE_COMPLIANCE = 'plan:score-compliance';

    /**
     * What each gated command refuses to start without, keyed by the command it
     * gates. Read by both the ->when() gates in routes/console.php and the Pulse
     * scheduler timeline, so the chain is described in one place.
     *
     * @var array<string, list<string>>
     */
    public const array PREREQUISITES = [
        'ai:weekly-recap' => [self::STREAK_SETTLE],
        'plan:regenerate' => [self::PLAN_CLOSE_FINISHED_RACES, self::PLAN_SCORE_COMPLIANCE],
    ];

    /** @return list<string> */
    public static function prerequisitesFor(string $command): array
    {
        return self::PREREQUISITES[$command] ?? [];
    }

    public static function prerequisitesMet(string $command): bool
    {
        foreach (self::prerequisitesFor($command) as $prerequisite) {
            if (! self::isDoneToday($prerequisite)) {
                return false;
            }
        }

        return true;
    }

    public static function markDoneToday(string $command): void
    {
        Cache::put(self::cacheKey($command), true, now()->addHours(25));
    }

    public static function isDoneToday(string $command): bool
    {
        return Cache::has(self::cacheKey($command));
    }

    private static function cacheKey(string $command): string
    {
        return 'scheduler-chain:'.$command.':'.now()->toDateString();
    }
}
