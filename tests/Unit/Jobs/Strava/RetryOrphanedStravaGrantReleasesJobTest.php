<?php

declare(strict_types=1);

use App\Jobs\Strava\RetryOrphanedStravaGrantReleasesJob;
use App\Services\Ops\MaintainerAlerter;
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

    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('jobRecovered')->once()->with('RetryOrphanedStravaGrantReleasesJob');

    new RetryOrphanedStravaGrantReleasesJob()->handle($releases, $alerter);

    Http::assertNothingSent();
});

it('pages through the alerter when its final attempt fails', function (): void {
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('jobFailed')->once()->with('RetryOrphanedStravaGrantReleasesJob');
    app()->instance(MaintainerAlerter::class, $alerter);

    new RetryOrphanedStravaGrantReleasesJob()->failed();
});
