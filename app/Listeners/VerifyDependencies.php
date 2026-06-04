<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Deepens the `/up` health route: it normally only proves PHP booted. By
 * throwing here, an unreachable MySQL or Redis flips `/up` to 500 (and the
 * docker healthcheck to unhealthy) instead of reporting a half-dead app as OK.
 */
class VerifyDependencies
{
    public function handle(DiagnosingHealth $event): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            throw new RuntimeException('health: mysql unreachable', previous: $e);
        }

        foreach (['default', 'cache'] as $connection) {
            try {
                Redis::connection($connection)->ping();
            } catch (Throwable $e) {
                throw new RuntimeException("health: redis [{$connection}] unreachable", previous: $e);
            }
        }
    }
}
