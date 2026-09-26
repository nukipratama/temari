<?php

declare(strict_types=1);

use App\Jobs\Strava\RetryOrphanedStravaGrantReleasesJob;
use App\Services\Strava\StravaClient;
use App\Services\Strava\StravaGrantLedger;
use App\Services\Strava\StravaGrantReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('delegates the retry sweep to the grant release service', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $releases = new StravaGrantReleaseService(new StravaClient(), app(StravaGrantLedger::class));

    new RetryOrphanedStravaGrantReleasesJob()->handle($releases);

    Http::assertNothingSent();
});
