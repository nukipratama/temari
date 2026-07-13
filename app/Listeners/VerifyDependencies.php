<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use RuntimeException;
use Throwable;

/**
 * Deepens the `/up` health route: it normally only proves PHP booted. By
 * throwing here, an unreachable MySQL/analytics DB/Redis or a dead/paused
 * Horizon flips `/up` to 500 (and the docker healthcheck to unhealthy)
 * instead of reporting a half-dead app as OK.
 */
class VerifyDependencies
{
    public function __construct(private readonly MasterSupervisorRepository $masters)
    {
    }

    public function handle(DiagnosingHealth $event): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            throw new RuntimeException('health: mysql unreachable', previous: $e);
        }

        try {
            DB::connection('analytics')->getPdo();
        } catch (Throwable $e) {
            throw new RuntimeException('health: analytics db unreachable', previous: $e);
        }

        // A write-probe, not ping(): during a Redis restart replaying a large AOF,
        // ping() answers PONG while the server is still LOADING and rejects every
        // read/write, so sessions, cache, queues and Horizon dispatch all fail
        // behind a healthy-looking /up. Writing a short-TTL sentinel and reading it
        // back is the honest gate (mirrors the container healthcheck's SET probe).
        foreach (['default', 'cache'] as $connection) {
            try {
                $sentinel = 'health:probe:'.$connection.':'.Str::random(12);
                $redis = Redis::connection($connection);
                $redis->setex($sentinel, 5, '1');
                $readBack = $redis->get($sentinel);
                $redis->del($sentinel);

                if ((string) $readBack !== '1') {
                    throw new RuntimeException('sentinel read-back mismatch');
                }
            } catch (Throwable $e) {
                throw new RuntimeException("health: redis [{$connection}] unreachable", previous: $e);
            }
        }

        /** @var list<object{status: string}> $masters */
        $masters = $this->masters->all();

        if ($masters === []) {
            throw new RuntimeException('health: horizon inactive');
        }

        if (collect($masters)->contains(fn (object $master): bool => $master->status === 'paused')) {
            throw new RuntimeException('health: horizon paused');
        }
    }
}
