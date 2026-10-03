<?php

declare(strict_types=1);

namespace App\Jobs\Run;

use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use App\Models\User;
use App\Services\Run\Plan\PlanReconciliationService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

#[Backoff([30, 120])]
#[MaxExceptions(3)]
#[Tries(10)]
#[UniqueFor(3600)]
final class ReconcilePlanJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
            new WithoutOverlapping("plan-reconciliation:{$this->userId}")
                ->releaseAfter(10)
                ->expireAfter(150),
        ];
    }

    public function handle(PlanReconciliationService $reconciliation): void
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Ingest);

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
