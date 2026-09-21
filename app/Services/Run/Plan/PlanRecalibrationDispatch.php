<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Models\User;

final class PlanRecalibrationDispatch
{
    private static bool $suppressed = false;

    public static function forUserId(int $userId): void
    {
        if (self::$suppressed || ! User::query()->notDemo()->whereKey($userId)->exists()) {
            return;
        }

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
