<?php

declare(strict_types=1);

use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\PersonalRecord;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\StravaConnection;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->pipeline = app(ActivityPipeline::class);
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
            'start_latlng' => [-6.2253, 106.8090],
        ]),
        'strava.com/api/v3/activities/999/streams*' => Http::response([
            'time' => ['data' => [0, 60, 120]],
            'heartrate' => ['data' => [120, 140, 150]],
        ]),
    ]);

    $this->pipeline->ingest($activity);

    $detail = ActivityDetail::query()->where('activity_id', $activity->id)->first();
    expect($detail)->not->toBeNull()
        ->and($detail->name)->toBe('Morning Run')
        ->and($detail->distance)->toEqualWithDelta(10000.0, 0.01)
        ->and($detail->summary_polyline)->toBe('poly123')
        ->and($detail->start_lat)->toEqualWithDelta(-6.2253, 1e-5)
        ->and($detail->start_lng)->toEqualWithDelta(106.8090, 1e-5);

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

    $this->pipeline->ingest($activity);

    expect($activity->fresh()->detail_fail_count)->toBe(3)
        ->and($activity->fresh()->analyzed_at)->toBeNull();
});

it('marks analyzed_at after max attempts so we stop hammering Strava', function (): void {
    $activity = makeActivityWithConnection();
    $activity->update(['detail_fail_count' => 4]);

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response(['error' => 'down'], 500),
    ]);

    $this->pipeline->ingest($activity);

    expect($activity->fresh()->detail_fail_count)->toBe(5)
        ->and($activity->fresh()->analyzed_at)->not->toBeNull();
});

it('stops immediately on a 404 detail (deleted activity), no further retries', function (): void {
    $activity = makeActivityWithConnection();

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response(['error' => 'Record Not Found'], 404),
    ]);

    $this->pipeline->ingest($activity);

    // analyzed_at stamped so the row is treated as handled and never refetched,
    // even though we are nowhere near DETAIL_FETCH_MAX_ATTEMPTS.
    expect($activity->fresh()->detail_fail_count)->toBe(1)
        ->and($activity->fresh()->analyzed_at)->not->toBeNull();
});

it('keeps retrying on a 5xx detail (transient)', function (): void {
    $activity = makeActivityWithConnection();

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response(['error' => 'down'], 503),
    ]);

    $this->pipeline->ingest($activity);

    // 5xx is transient: increment but leave analyzed_at null so we retry.
    expect($activity->fresh()->detail_fail_count)->toBe(1)
        ->and($activity->fresh()->analyzed_at)->toBeNull();
});

it('propagates a rate-limit exception so the job can re-queue with backoff', function (): void {
    $activity = makeActivityWithConnection();
    $userId = $activity->user_id;

    // Exhaust the 15-minute bucket so the next client call throws before any HTTP.
    for ($i = 0; $i < 200; $i++) {
        RateLimiter::hit("strava-api:{$userId}:15min", 15 * 60);
    }
    Http::fake();

    expect(fn () => $this->pipeline->ingest($activity))
        ->toThrow(StravaRateLimitedException::class);

    // The rate-limited fetch must NOT count as a detail failure.
    expect($activity->fresh()->detail_fail_count)->toBe(0)
        ->and($activity->fresh()->analyzed_at)->toBeNull();
});

it('still stores detail when streams 404 (best-effort)', function (): void {
    $activity = makeActivityWithConnection();

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response(['name' => 'R', 'distance' => 5000]),
        'strava.com/api/v3/activities/999/streams*' => Http::response(['error' => 'gone'], 404),
    ]);

    $this->pipeline->ingest($activity);

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

    $this->pipeline->ingest($activity);

    $detail = ActivityDetail::query()->where('activity_id', $activity->id)->first();
    expect($detail->name)->toBe('fresh name')
        ->and($activity->fresh()->detail_fail_count)->toBe(0);
});

it('skips when user has no Strava connection', function (): void {
    $activity = Activity::factory()->create();
    Http::fake();

    $this->pipeline->ingest($activity);

    expect(ActivityDetail::query()->where('activity_id', $activity->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('treats non-array Strava detail as a fetch failure', function (): void {
    $activity = makeActivityWithConnection();
    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response('"unexpected scalar"'),
    ]);

    $this->pipeline->ingest($activity);

    expect($activity->fresh()->detail_fail_count)->toBe(1)
        ->and(ActivityDetail::query()->where('activity_id', $activity->id)->exists())->toBeFalse();
});

it('computes stream_summary + Edwards TRIMP from the streams blob', function (): void {
    $activity = makeActivityWithConnection();

    // 60 min: 30 min Z2 then 30 min Z3.
    $time = [];
    $hr = [];
    $velocity = [];
    for ($t = 0; $t <= 3600; $t += 60) {
        $time[] = $t;
        $hr[] = $t < 1800 ? 145 : 160;
        $velocity[] = 2.78;
    }

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response([
            'name' => 'Easy 10K',
            'start_date_local' => '2026-05-10 06:30:00',
            'distance' => 10000.0,
            'moving_time' => 3600,
            'elapsed_time' => 3600,
            'has_heartrate' => true,
            'splits_metric' => [],
            'map' => null,
        ]),
        'strava.com/api/v3/activities/999/streams*' => Http::response([
            'time' => ['data' => $time],
            'heartrate' => ['data' => $hr],
            'velocity_smooth' => ['data' => $velocity],
        ]),
    ]);

    $this->pipeline->ingest($activity);

    $detail = ActivityDetail::query()->where('activity_id', $activity->id)->first();

    // ~30min×2 + ~30min×3 = ~150 TRIMP.
    expect($detail->trimp_edwards)->toBeFloat()->toBeGreaterThan(100)->toBeLessThan(200);
    expect($detail->stream_summary)->toBeArray()
        ->and($detail->stream_summary)->toHaveKey('time_in_zone_min')
        ->and($detail->stream_summary)->toHaveKey('time_in_zone_pct');
});

it('writes weather columns when streams expose a lat/lng', function (): void {
    $activity = makeActivityWithConnection();

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response([
            'name' => 'Morning Run',
            'start_date_local' => '2026-05-10 06:00:00',
            'distance' => 5000,
            'moving_time' => 1800,
            'elapsed_time' => 1800,
            'splits_metric' => [],
        ]),
        'strava.com/api/v3/activities/999/streams*' => Http::response([
            'time' => ['data' => [0, 60, 120]],
            'latlng' => ['data' => [[-6.2, 106.8]]],
        ]),
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T06:00'],
                'temperature_2m' => [27.5],
                'relative_humidity_2m' => [82],
                'precipitation' => [0],
            ],
        ]),
    ]);

    $this->pipeline->ingest($activity);

    $detail = ActivityDetail::query()->where('activity_id', $activity->id)->first();
    expect($detail->weather_temp_c)->toBe(28)
        ->and($detail->weather_humidity_pct)->toBe(82)
        ->and($detail->weather_rain_detected)->toBeFalse();
});

it('leaves weather columns null when Open-Meteo returns nothing', function (): void {
    $activity = makeActivityWithConnection();

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response([
            'name' => 'Run', 'start_date_local' => '2026-05-10 06:00:00',
            'distance' => 5000, 'moving_time' => 1800, 'elapsed_time' => 1800,
            'splits_metric' => [],
        ]),
        'strava.com/api/v3/activities/999/streams*' => Http::response([
            'time' => ['data' => [0, 60]],
            'latlng' => ['data' => [[-6.2, 106.8]]],
        ]),
        'api.open-meteo.com/*' => Http::response([], 500),
    ]);

    $this->pipeline->ingest($activity);

    $detail = ActivityDetail::query()->where('activity_id', $activity->id)->first();
    expect($detail->weather_temp_c)->toBeNull();
});

it('produces a run card + post_run story line on a successful ingest', function (): void {
    $activity = makeActivityWithConnection();

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response([
            'name' => 'Easy 5K',
            'start_date_local' => '2026-05-10 06:30:00',
            'distance' => 5000,
            'moving_time' => 1800,
            'elapsed_time' => 1800,
            'splits_metric' => [],
        ]),
        'strava.com/api/v3/activities/999/streams*' => Http::response([
            'time' => ['data' => [0, 60, 120]],
            'heartrate' => ['data' => [140, 145, 150]],
        ]),
    ]);

    $this->pipeline->ingest($activity);

    expect(RunCard::query()->where('activity_id', $activity->id)->exists())->toBeTrue()
        ->and(StoryLine::query()
            ->where('activity_id', $activity->id)
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->exists())->toBeTrue();
});

it('inserts a PR row when the activity beats the user\'s ledger', function (): void {
    $activity = makeActivityWithConnection();

    $splits = [];
    for ($k = 1; $k <= 5; $k++) {
        $splits[] = ['split' => $k, 'distance' => 1000, 'moving_time' => 360, 'elapsed_time' => 360];
    }

    Http::fake([
        'strava.com/api/v3/activities/999' => Http::response([
            'name' => 'Tempo 5K',
            'start_date_local' => '2026-05-10 06:30:00',
            'distance' => 5000,
            'moving_time' => 1800,
            'elapsed_time' => 1800,
            'splits_metric' => $splits,
        ]),
        'strava.com/api/v3/activities/999/streams*' => Http::response([]),
    ]);

    $this->pipeline->ingest($activity);

    expect(PersonalRecord::query()
        ->where('user_id', $activity->user_id)
        ->where('category', '5km')
        ->value('value_sec'))->toBe(1800.0);
});
