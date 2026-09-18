<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use App\Console\Commands\Strava\HydrateBacklogCommand;
use App\Services\Run\Ingest\DetailHydrator;
use App\Services\Strava\StravaClient;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Starts one athlete's backlog drain the moment their summary backfill lands,
 * instead of waiting for the next `strava:hydrate-backlog` tick. Reuses
 * {@see HydrateBacklogCommand::budget()} and
 * {@see HydrateBacklogCommand::hydrateFor()} unchanged, so it is paced by the
 * same background read headroom and stays oldest-first. A concurrent cron
 * tick hydrating the same runs collapses onto this job's dispatches via
 * {@see IngestActivityJob}'s existing `ShouldBeUnique` lock — nothing new is
 * needed to keep the two from double-hydrating.
 */
class HydrateBacklogForUserJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
    }

    public function handle(AppConfig $config, StravaClient $client, DetailHydrator $hydrator, HydrateBacklogCommand $command): void
    {
        if (! $config->boolean(AppConfigKey::StravaEnabled)) {
            return;
        }

        $budget = $command->budget($client);

        if ($budget < 1) {
            return;
        }

        $command->hydrateFor($hydrator, $this->userId, $budget);
    }
}
