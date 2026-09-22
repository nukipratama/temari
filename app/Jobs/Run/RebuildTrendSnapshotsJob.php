<?php

declare(strict_types=1);

namespace App\Jobs\Run;

use App\Models\User;
use App\Services\Run\Trend\TrendSnapshotRepairService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class RebuildTrendSnapshotsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $userId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping("trend-snapshots:{$this->userId}")
                ->releaseAfter(10)
                ->expireAfter(3600),
        ];
    }

    public function handle(TrendSnapshotRepairService $repair): void
    {
        if (User::query()->notDemo()->whereKey($this->userId)->doesntExist()) {
            return;
        }

        $repair->drain($this->userId);
    }
}
