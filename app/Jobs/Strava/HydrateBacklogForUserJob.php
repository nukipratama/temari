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
 * Starts one athlete's backlog drain immediately after their summary backfill
 * lands, recent-first (`$recentFirst`) unlike the plain oldest-first cron
 * tick — see docs/decisions/history-narrates-on-demand.md.
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

        $command->hydrateFor($hydrator, $this->userId, $budget, recentFirst: true);
    }
}
