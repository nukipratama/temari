<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Support\Facades\Cache;

final class SchedulerChain
{
    public const string STREAK_SETTLE = 'streak:settle';

    public const string PLAN_CLOSE_FINISHED_RACES = 'plan:close-finished-races';

    public const string PLAN_SCORE_COMPLIANCE = 'plan:score-compliance';

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
