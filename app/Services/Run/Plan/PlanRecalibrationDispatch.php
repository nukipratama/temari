<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class PlanRecalibrationDispatch
{
    private static bool $suppressed = false;

    public static function forUserId(int $userId): void
    {
        if (self::$suppressed) {
            return;
        }

        $user = User::query()->notDemo()->find($userId);
        if ($user === null) {
            return;
        }

        $lock = Cache::lock(
            RecalibrateTrainingHistoryJob::overlapLockKey($userId),
            RecalibrateTrainingHistoryJob::overlapLockTtlSeconds(),
        );
        if (! $lock->get()) {
            RecalibrateTrainingHistoryJob::markDirty($userId);

            return;
        }
        $lock->release();

        $user->forceFill([
            'plan_recalibration_started_at' => now(),
            'plan_recalibration_completed_at' => null,
        ])->saveQuietly();

        RecalibrateTrainingHistoryJob::dispatch($userId)->afterCommit();
    }

    public static function withoutDispatching(callable $callback): mixed
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $previous;
        }
    }
}
