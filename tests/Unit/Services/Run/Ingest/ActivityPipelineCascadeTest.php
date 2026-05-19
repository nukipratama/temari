<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeDailyGreetingJob;
use App\Jobs\AI\AnalyzeTrendCaptionJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    RateLimiter::clear('strava-api:15min');
    RateLimiter::clear('strava-api:daily');
});

function ingestSeed(): Activity
{
    $activity = Activity::factory()->create(['strava_external_id' => 999]);
    StravaConnection::factory()->for($activity->user)->create([
        'access_token' => 'tok',
        'token_expires_at' => Carbon::now()->addHours(2),
    ]);

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response([
            'name' => 'Run',
            'start_date_local' => '2026-05-10 06:30:00',
            'distance' => 5000.0,
            'moving_time' => 1500,
            'elapsed_time' => 1500,
            'average_speed' => 3.33,
            'total_elevation_gain' => 10.0,
            'has_heartrate' => false,
            'splits_metric' => [],
            'map' => ['summary_polyline' => 'poly'],
            'start_latlng' => null,
        ]),
        'strava.com/api/v3/activities/999/streams*' => Http::response([]),
    ]);

    return $activity;
}

it('cascades 5 dispatches after a successful ingest (activity + briefing + greeting + trend + weekly)', function (): void {
    $activity = ingestSeed();

    app(ActivityPipeline::class)->ingest($activity);

    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertDispatched(AnalyzeBriefingJob::class);
    Bus::assertDispatched(AnalyzeDailyGreetingJob::class);
    Bus::assertDispatched(AnalyzeTrendCaptionJob::class);
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('dispatches AnalyzeActivityJob exactly once per ingest (debounced via the group routing)', function (): void {
    $activity = ingestSeed();

    app(ActivityPipeline::class)->ingest($activity);

    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);
});

it('uses today as discriminator for briefing/greeting/trend', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00');
    $activity = ingestSeed();

    app(ActivityPipeline::class)->ingest($activity);

    Bus::assertDispatched(
        AnalyzeBriefingJob::class,
        fn (AnalyzeBriefingJob $job): bool => $job->discriminator === '2026-05-19',
    );
    Bus::assertDispatched(
        AnalyzeDailyGreetingJob::class,
        fn (AnalyzeDailyGreetingJob $job): bool => true, // row job dispatched
    );
    Carbon::setTestNow();
});
