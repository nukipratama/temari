<?php

declare(strict_types=1);

namespace App\Jobs\Run;

use App\Enums\PlanRegenerationReason;
use App\Models\User;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanRegenerationService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RegeneratePlanJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $uniqueFor = 3600;

    public int $timeout = 120;

    public function __construct(
        public readonly int $userId,
        public readonly PlanRegenerationReason $reason,
    ) {
    }

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->reason->value}";
    }

    public function handle(Periodizer $periodizer, PlanRegenerationService $regeneration): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        try {
            $periodizer->regenerate($user);
        } catch (LockTimeoutException) {
            $this->release(10);

            return;
        }

        $regeneration->requestNarrationAfterQueuedRegeneration($user, $this->reason);
    }
}
