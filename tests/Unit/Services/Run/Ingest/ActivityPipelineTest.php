<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\StravaConnection;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Strava\StravaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('strava-api:15min');
    RateLimiter::clear('strava-api:daily');
});

function makeActivityWithConnection(): Activity
{
    $activity = Activity::factory()->create(['strava_external_id' => 999]);
    StravaConnection::factory()->for($activity->user)->create([
        'access_token' => 'tok',
        'token_expires_at' => Carbon::now()->addHours(2),
    ]);

    return $activity;
}

it('stores detail and streams on successful fetch', function (): void {
    $activity = makeActivityWithConnection();

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response([
            'name' => 'Morning Run',
            'start_date_local' => '2026-05-10 06:30:00',
            'distance' => 10000.0,
            'moving_time' => 3600,
            'elapsed_time' => 3700,
            'average_speed' => 2.78,
            'total_elevation_gain' => 50.0,
            'has_heartrate' => true,
            'average_heartrate' => 152.4,
            'max_heartrate' => 175,
            'average_cadence' => 82.5,
            'calories' => 600.0,
            'splits_metric' => [['split' => 1]],
            'map' => ['summary_polyline' => 'poly123'],
        ]),
        'strava.com/api/v3/activities/999/streams*' => Http::response([
            'time' => ['data' => [0, 60, 120]],
            'heartrate' => ['data' => [120, 140, 150]],
        ]),
    ]);

    (new ActivityPipeline(new StravaClient()))->ingest($activity);

    $detail = ActivityDetail::query()->where('activity_id', $activity->id)->first();
    expect($detail)->not->toBeNull()
        ->and($detail->name)->toBe('Morning Run')
        ->and($detail->distance)->toEqualWithDelta(10000.0, 0.01)
        ->and($detail->summary_polyline)->toBe('poly123');

    $stream = ActivityStream::query()->where('activity_id', $activity->id)->first();
    expect($stream)->not->toBeNull()
        ->and($stream->data['time']['data'])->toBe([0, 60, 120]);

    expect($activity->fresh()->analyzed_at)->not->toBeNull()
        ->and($activity->fresh()->detail_fail_count)->toBe(0);
});

it('increments detail_fail_count on detail fetch failure', function (): void {
    $activity = makeActivityWithConnection();
    $activity->update(['detail_fail_count' => 2]);

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response(['error' => 'down'], 500),
    ]);

    (new ActivityPipeline(new StravaClient()))->ingest($activity);

    expect($activity->fresh()->detail_fail_count)->toBe(3)
        ->and($activity->fresh()->analyzed_at)->toBeNull();
});

it('marks analyzed_at after max attempts so we stop hammering Strava', function (): void {
    $activity = makeActivityWithConnection();
    $activity->update(['detail_fail_count' => 4]);

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response(['error' => 'down'], 500),
    ]);

    (new ActivityPipeline(new StravaClient()))->ingest($activity);

    expect($activity->fresh()->detail_fail_count)->toBe(5)
        ->and($activity->fresh()->analyzed_at)->not->toBeNull();
});

it('still stores detail when streams 404 (best-effort)', function (): void {
    $activity = makeActivityWithConnection();

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response(['name' => 'R', 'distance' => 5000]),
        'strava.com/api/v3/activities/999/streams*' => Http::response(['error' => 'gone'], 404),
    ]);

    (new ActivityPipeline(new StravaClient()))->ingest($activity);

    expect(ActivityDetail::query()->where('activity_id', $activity->id)->exists())->toBeTrue()
        ->and(ActivityStream::query()->where('activity_id', $activity->id)->exists())->toBeFalse()
        ->and($activity->fresh()->analyzed_at)->not->toBeNull();
});

it('is idempotent — re-ingesting refreshes detail and clears fail count', function (): void {
    $activity = makeActivityWithConnection();
    ActivityDetail::factory()->for($activity)->create(['name' => 'old name']);
    $activity->update(['detail_fail_count' => 3]);

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response(['name' => 'fresh name', 'distance' => 5000]),
        'strava.com/api/v3/activities/999/streams*' => Http::response([]),
    ]);

    (new ActivityPipeline(new StravaClient()))->ingest($activity);

    $detail = ActivityDetail::query()->where('activity_id', $activity->id)->first();
    expect($detail->name)->toBe('fresh name')
        ->and($activity->fresh()->detail_fail_count)->toBe(0);
});

it('skips when user has no Strava connection', function (): void {
    $activity = Activity::factory()->create();
    Http::fake();

    (new ActivityPipeline(new StravaClient()))->ingest($activity);

    expect(ActivityDetail::query()->where('activity_id', $activity->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});
