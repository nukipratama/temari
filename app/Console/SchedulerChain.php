<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

final class SchedulerChain
{
    public const string PLAN_CLOSE_FINISHED_RACES = 'plan:close-finished-races';

    public const string PLAN_SCORE_COMPLIANCE = 'plan:score-compliance';

    public const string PLAN_REGENERATE = 'plan:regenerate';

    /**
     * What each gated command refuses to start without, keyed by the command it
     * gates. Read by both the ->when() gates in routes/console.php and the Pulse
     * scheduler timeline, so the chain is described in one place.
     *
     * @var array<string, list<string>>
     */
    public const array PREREQUISITES = [
        self::PLAN_REGENERATE => [self::PLAN_CLOSE_FINISHED_RACES, self::PLAN_SCORE_COMPLIANCE],
    ];

    /** @return list<string> */
    public static function prerequisitesFor(string $command): array
    {
        return self::PREREQUISITES[$command] ?? [];
    }

    public static function prerequisitesMet(string $command): bool
    {
        return array_all(self::prerequisitesFor($command), fn ($prerequisite) => self::isDoneToday($prerequisite));
    }

    public static function markDoneToday(string $command): void
    {
        self::store()->put(self::todayKey($command), true, now()->addHours(25));
    }

    public static function isDoneToday(string $command): bool
    {
        return self::store()->has(self::todayKey($command));
    }

    public static function markDoneThisWeek(string $command): void
    {
        self::store()->put(self::weekKey($command), true, now()->addDays(8));
    }

    public static function isDoneThisWeek(string $command): bool
    {
        return self::store()->has(self::weekKey($command));
    }

    private static function store(): Repository
    {
        return Cache::store('durable');
    }

    private static function todayKey(string $command): string
    {
        return 'scheduler-chain:'.$command.':'.now()->toDateString();
    }

    private static function weekKey(string $command): string
    {
        return 'scheduler-chain:'.$command.':'.now()->isoFormat('GGGG-[W]WW');
    }
}
