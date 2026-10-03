<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use Illuminate\Foundation\Queue\Queueable;
use App\Services\Strava\StravaGrantReleaseService;
use Illuminate\Contracts\Queue\ShouldQueue;

final class RetryOrphanedStravaGrantReleasesJob implements ShouldQueue
{
    use Queueable;

    public function handle(StravaGrantReleaseService $releases): void
    {
        $releases->retryOrphans();
    }
}
