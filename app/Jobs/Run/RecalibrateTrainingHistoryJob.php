<?php

declare(strict_types=1);

namespace App\Jobs\Run;

use App\Models\User;
use App\Services\Run\Plan\PlanRecalibrationService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class RecalibrateTrainingHistoryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $uniqueFor = 3600;

    public int $timeout = 120;

    private const int LOCK_TTL_SECONDS = 150;

    public function __construct(public readonly int $userId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
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
