<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
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

        foreach (['default', 'cache'] as $connection) {
            try {
                Redis::connection($connection)->ping();
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
