<?php

declare(strict_types=1);

namespace App\Jobs\Gamification;

use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use App\Console\SchedulerChain;
use App\Models\User;
use App\Services\Gamification\StreakSettlementService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

#[Backoff([30, 120])]
#[Tries(3)]
#[UniqueFor(3600)]
final class SettleStreakWeeksJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping("streak-settle:{$this->userId}")
                ->releaseAfter(10)
                ->expireAfter(3600),
        ];
    }

    public function handle(StreakSettlementService $settlement): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        if (! $settlement->settle($user)) {
            self::dispatch($this->userId);

            return;
        }

        if ($settlement->allUsersSettled()) {
            SchedulerChain::markDoneToday(SchedulerChain::STREAK_SETTLE);
        }
    }
}
