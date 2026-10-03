<?php

declare(strict_types=1);

namespace App\Jobs\Run;

use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use App\Models\User;
use App\Services\Run\Metrics\WeeklyAggregator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

#[Backoff([30, 120])]
#[Tries(3)]
#[UniqueFor(900)]
final class RollWeeklySnapshotsForwardJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(WeeklyAggregator $aggregator): void
    {
        $user = User::query()->notDemo()->find($this->userId);
        if ($user === null) {
            return;
        }

        $aggregator->rollForwardDirty($user);
    }
}
