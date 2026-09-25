<?php

declare(strict_types=1);

namespace App\Jobs\Run;

use App\Models\User;
use App\Services\Run\Plan\PlanRecalibrationService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;

final class RecalibrateTrainingHistoryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $uniqueFor = 3600;

    public int $timeout = 60;

    private const int LOCK_TTL_SECONDS = 70;

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
        return [self::overlapMiddleware($this->userId)];
    }

    public static function overlapMiddleware(int $userId): WithoutOverlapping
    {
        return new WithoutOverlapping("training-recalibration:{$userId}")
            ->shared()
            ->releaseAfter(1)
            ->expireAfter(self::LOCK_TTL_SECONDS);
    }

    public static function overlapLockKey(int $userId): string
    {
        return self::overlapMiddleware($userId)->getLockKey(new self($userId));
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
        Cache::put(self::dirtyMarkerKey($userId), true, self::LOCK_TTL_SECONDS * 2);
    }

    public function handle(PlanRecalibrationService $recalibration): void
    {
        $user = User::query()->notDemo()->find($this->userId);
        if ($user === null) {
            return;
        }

        $recalibration->recalibrate($user);

        if (Cache::pull(self::dirtyMarkerKey($this->userId))) {
            self::dispatch($this->userId)->afterCommit();
        }
    }
}
