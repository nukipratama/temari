<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('plan:forget-volume-floors')]
#[Description('Retire every cached volume-floor solve so the next read re-solves with the code now deployed')]
class ForgetVolumeFloorsCommand extends Command
{
    public function handle(): int
    {
        // Runs inside `artisan optimize` during deploy: a cache outage must not fail the
        // deploy, since a floor left cached expires within a day on its own.
        try {
            $generation = Cache::store('durable')->increment(TrainingBaseline::VOLUME_FLOOR_GENERATION_KEY);
        } catch (Throwable $e) {
            report($e);
            $this->warn('Could not bump the volume-floor cache generation; cached floors expire within a day.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Volume-floor cache generation is now %d.', $generation));

        return self::SUCCESS;
    }
}
