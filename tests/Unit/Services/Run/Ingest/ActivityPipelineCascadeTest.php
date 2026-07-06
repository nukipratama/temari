<?php

declare(strict_types=1);

use App\Events\ActivityIngested;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Fake only ActivityIngested so the AI fan-out (DispatchPostRunAnalysis) does
    // not run here — the fan-out is covered by DispatchPostRunAnalysisTest. The
    // pipeline's job is simply to emit the event once the run is persisted.
    Event::fake([ActivityIngested::class]);
    // ingest() also calls RunCardFactory directly (independent of the faked
    // event above), which queues a real AnalyzeCardFlavorJob under the sync test
    // queue connection. That job has its own dedicated test.
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

it('emits ActivityIngested with the activity id after a successful ingest', function (): void {
    $activity = ingestSeed();

    $this->pipeline->ingest($activity);

    Event::assertDispatched(
        ActivityIngested::class,
        fn (ActivityIngested $event): bool => $event->activityId === $activity->id,
    );
});

it('does not emit ActivityIngested when the detail fetch fails', function (): void {
    $activity = Activity::factory()->stub()->create(['strava_external_id' => 999]);
    StravaConnection::factory()->for($activity->user)->create([
        'access_token' => 'tok',
        'token_expires_at' => Carbon::now()->addHours(2),
    ]);
    Http::fake(['strava.com/api/v3/activities/999' => Http::response(['error' => 'down'], 500)]);

    $this->pipeline->ingest($activity);

    Event::assertNotDispatched(ActivityIngested::class);
});
