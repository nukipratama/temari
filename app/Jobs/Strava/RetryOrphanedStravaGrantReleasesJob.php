<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use App\Services\Strava\StravaGrantReleaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RetryOrphanedStravaGrantReleasesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(StravaGrantReleaseService $releases): void
    {
        $releases->retryOrphans();
    }
}
