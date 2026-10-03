<?php

declare(strict_types=1);

namespace App\Jobs\Run;

use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\UniqueFor;
use DateTimeInterface;
use App\Models\User;
use App\Services\Run\Plan\PlanRecalibrationService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

#[Backoff([30, 120])]
#[MaxExceptions(3)]
#[Timeout(120)]
#[UniqueFor(3600)]
final class RecalibrateTrainingHistoryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const int LOCK_TTL_SECONDS = 150;

    public function __construct(public readonly int $userId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public static function overlapLockKey(int $userId): string
    {
        return "training-recalibration:{$userId}";
    }

    public static function overlapLockTtlSeconds(): int
    {
        return self::LOCK_TTL_SECONDS;
    }

    public static function dirtyMarkerKey(int $userId): string
    {
        return "training-recalibration:dirty:{$userId}";
    }

    public static function markDirty(int $userId): void
    {
        Cache::put(self::dirtyMarkerKey($userId), true, max(self::LOCK_TTL_SECONDS * 2, 3600));
    }

    public function handle(PlanRecalibrationService $recalibration): void
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Ingest);

        $user = User::query()->notDemo()->find($this->userId);
        if ($user === null) {
            return;
        }

        try {
            $recalibration->recalibrate($user, lockTtlSeconds: self::LOCK_TTL_SECONDS);
        } catch (LockTimeoutException) {
            $this->release(10);
        }
    }
}
