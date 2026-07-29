<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Liveness signal for the `scheduler` service: every minute the schedule stamps
 * a unix timestamp on the durable Redis connection, and the container's
 * healthcheck runs the same command with `--check` to assert that stamp is
 * still fresh.
 *
 * Two constraints the code cannot show: the stamp must live on the durable
 * `default` connection (`cache` is allkeys-lru, and an evicted stamp reads as a
 * dead scheduler), and it must not reuse the MySQL-backed
 * {@see \App\Models\ScheduledTaskRun} heartbeat, since a Docker `CMD`
 * healthcheck cannot depend on MySQL being up.
 */
#[Signature('schedule:heartbeat {--check : Assert the stored heartbeat is fresh instead of writing one}')]
#[Description('Stamp (or verify) the scheduler liveness heartbeat on the durable Redis connection.')]
class ScheduleHeartbeatCommand extends Command
{
    public const string KEY = 'scheduler:heartbeat';

    public const int STALE_AFTER_SECONDS = 300;

    public const int KEY_TTL_SECONDS = 3600;

    public function handle(): int
    {
        return $this->option('check') ? $this->assertFresh() : $this->stamp();
    }

    private function stamp(): int
    {
        try {
            Redis::connection('default')->setex(self::KEY, self::KEY_TTL_SECONDS, (string) Carbon::now()->getTimestamp());
        } catch (Throwable $e) {
            $this->error('scheduler heartbeat NOT written: redis unreachable ('.$e->getMessage().')');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function assertFresh(): int
    {
        try {
            $stamp = Redis::connection('default')->get(self::KEY);
        } catch (Throwable $e) {
            $this->error('scheduler heartbeat UNKNOWN: redis unreachable ('.$e->getMessage().')');

            return self::FAILURE;
        }

        if (! is_string($stamp) || $stamp === '') {
            $this->error('scheduler heartbeat MISSING: no beat in the last '.self::KEY_TTL_SECONDS.'s');

            return self::FAILURE;
        }

        $age = Carbon::now()->getTimestamp() - (int) $stamp;

        if ($age > self::STALE_AFTER_SECONDS) {
            $this->error('scheduler heartbeat STALE: '.$age.'s old (max '.self::STALE_AFTER_SECONDS.'s)');

            return self::FAILURE;
        }

        $this->line('scheduler heartbeat OK: '.$age.'s old');

        return self::SUCCESS;
    }
}
