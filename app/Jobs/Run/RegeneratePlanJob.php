<?php

declare(strict_types=1);

namespace App\Jobs\Run;

use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\UniqueFor;
use DateTimeInterface;
use App\Enums\PlanRegenerationReason;
use App\Models\User;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanRegenerationService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

#[Backoff([30, 120])]
#[Timeout(120)]
#[UniqueFor(3600)]
final class RegeneratePlanJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly PlanRegenerationReason $reason,
    ) {
    }

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->reason->value}";
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(Periodizer $periodizer, PlanRegenerationService $regeneration): void
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::User);

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
