<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Laravel\Pulse\Facades\Pulse;

/**
 * Records the peak PHP heap of each queued job and artisan command into Pulse,
 * the measure the CLI `memory_limit` is sized against.
 */
final class RecordCliPeakMemory
{
    public function reset(): void
    {
        memory_reset_peak_usage();
    }

    public function job(JobProcessed|JobFailed $event): void
    {
        Pulse::record('peak_memory_job', $event->job->resolveName(), memory_get_peak_usage(true))->max()->avg()->count();
    }

    public function command(CommandFinished $event): void
    {
        Pulse::record('peak_memory_command', $event->command, memory_get_peak_usage(true))->max()->avg()->count();
    }
}
