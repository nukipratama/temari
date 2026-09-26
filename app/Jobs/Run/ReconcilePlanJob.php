<?php

declare(strict_types=1);

namespace App\Jobs\Run;

use App\Models\User;
use App\Services\Run\Plan\PlanReconciliationService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReconcilePlanJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 3;

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

    public function handle(PlanReconciliationService $reconciliation): void
    {
        if (User::query()->notDemo()->whereKey($this->userId)->doesntExist()) {
            return;
        }

        try {
            $reconciliation->drain($this->userId);
        } catch (LockTimeoutException) {
            $this->release(10);
        }
    }
}
