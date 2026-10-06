<?php

declare(strict_types=1);

namespace App\Console;

use App\Models\ScheduledTaskRun;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
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

    /**
     * Gated commands, by how often each must succeed. A closed gate skips every
     * tick in between, so these are late only once a whole period passes
     * without a success.
     *
     * @var list<string>
     */
    public const array DAILY_GATED = [self::PLAN_CLOSE_FINISHED_RACES, self::PLAN_SCORE_COMPLIANCE];

    /** @var list<string> */
    public const array WEEKLY_GATED = [self::PLAN_REGENERATE];

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
        self::store()->put(self::dayKey($command, now()), true, now()->addHours(49));
    }

    public static function isDoneToday(string $command): bool
    {
        return self::store()->has(self::dayKey($command, now()));
    }

    public static function markDoneThisWeek(string $command): void
    {
        self::store()->put(self::weekKey($command, now()), true, now()->addDays(15));
    }

    public static function isDoneThisWeek(string $command): bool
    {
        return self::store()->has(self::weekKey($command, now()));
    }

    /**
     * Late for the scheduler sweep: a gated command once its previous whole day
     * or ISO week passed without a success and the current one has none yet,
     * any other command once its heartbeat is stale.
     */
    public static function isLate(string $command, ?ScheduledTaskRun $run): bool
    {
        return match (true) {
            in_array($command, self::DAILY_GATED, true) => ! self::isDoneToday($command)
                && ! self::store()->has(self::dayKey($command, now()->subDay())),
            in_array($command, self::WEEKLY_GATED, true) => ! self::isDoneThisWeek($command)
                && ! self::store()->has(self::weekKey($command, now()->subWeek())),
            default => $run?->isStale() ?? false,
        };
    }

    private static function store(): Repository
    {
        return Cache::store('durable');
    }

    private static function dayKey(string $command, Carbon $day): string
    {
        return 'scheduler-chain:'.$command.':'.$day->toDateString();
    }

    private static function weekKey(string $command, Carbon $week): string
    {
        return 'scheduler-chain:'.$command.':'.$week->isoFormat('GGGG-[W]WW');
    }
}
