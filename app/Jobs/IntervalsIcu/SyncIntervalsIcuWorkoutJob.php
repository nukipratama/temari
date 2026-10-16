<?php

declare(strict_types=1);

namespace App\Jobs\IntervalsIcu;

use App\Models\IntervalsIcuConnection;
use App\Services\IntervalsIcu\IntervalsIcuSync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;

#[Tries(1)]
#[UniqueFor(900)]
final class SyncIntervalsIcuWorkoutJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
            new WithoutOverlapping("intervals-icu-sync:{$this->userId}")
                ->dontRelease()
                ->expireAfter(120),
        ];
    }

    public function handle(IntervalsIcuSync $sync): void
    {
        $connection = IntervalsIcuConnection::query()->firstWhere('user_id', $this->userId);
        if ($connection === null) {
            return;
        }

        $sync->sync($connection, Carbon::today());
    }
}
