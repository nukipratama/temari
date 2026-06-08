<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeDailyGreetingJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    $this->pipeline = app(ActivityPipeline::class);
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

it('cascades 4 dispatches after a successful ingest (activity + briefing + greeting + weekly)', function (): void {
    $activity = ingestSeed();

    $this->pipeline->ingest($activity);

    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertDispatched(AnalyzeBriefingJob::class);
    Bus::assertDispatched(AnalyzeDailyGreetingJob::class);
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('dispatches AnalyzeActivityJob exactly once per ingest (debounced via the group routing)', function (): void {
    $activity = ingestSeed();

    $this->pipeline->ingest($activity);

    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);
});

it('uses today as discriminator for briefing/greeting/trend', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00');
    $activity = ingestSeed();

    $this->pipeline->ingest($activity);

    Bus::assertDispatched(
        AnalyzeBriefingJob::class,
        fn (AnalyzeBriefingJob $job): bool => $job->discriminator === '2026-05-19',
    );
    // Row job carries no discriminator; asserting it dispatched at all is enough.
    Bus::assertDispatched(AnalyzeDailyGreetingJob::class);
    Carbon::setTestNow();
});
