<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use App\Services\Ops\MaintainerAlerter;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Strava\StravaGrantReleaseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\Timeout;

#[Timeout(60)]
#[Tries(3)]
final class RetryOrphanedStravaGrantReleasesJob implements ShouldQueue
{
    use Queueable;

    public function handle(StravaGrantReleaseService $releases, MaintainerAlerter $alerter): void
    {
        $releases->retryOrphans();

        $alerter->jobRecovered(class_basename(self::class));
    }

    public function failed(): void
    {
        app(MaintainerAlerter::class)->jobFailed(class_basename(self::class));
    }
}
