<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\AI\MaintainerAlerter;
use App\Services\Weather\OpenMeteoClient;
use App\Services\Weather\WeatherSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refetches weather for details with coords but a null weather_temp_c', function (): void {
    $this->mock(OpenMeteoClient::class)
        ->shouldReceive('fetchForActivity')
        ->once()
        ->andReturn(new WeatherSnapshot(tempC: 27, humidityPct: 80, rainDetected: false));

    $detail = ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'start_date_local' => now()->subDays(30),
        'weather_temp_c' => null,
    ]);

    $this->artisan('weather:backfill')->assertSuccessful();

    $detail->refresh();
    expect($detail->weather_temp_c)->toBe(27)
        ->and($detail->weather_humidity_pct)->toBe(80)
        ->and($detail->weather_rain_detected)->toBeFalse();
});

it('skips details that already have weather or are missing coords/start', function (): void {
    $this->mock(OpenMeteoClient::class)
        ->shouldReceive('fetchForActivity')
        ->never();

    // Already has weather.
    ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'start_date_local' => now()->subDays(30),
        'weather_temp_c' => 25,
    ]);
    // Missing coords.
    ActivityDetail::factory()->create([
        'start_lat' => null,
        'start_lng' => null,
        'start_date_local' => now()->subDays(30),
        'weather_temp_c' => null,
    ]);
    // Missing start time.
    ActivityDetail::factory()->create([
        'start_lat' => -7.0,
        'start_lng' => 112.0,
        'start_date_local' => null,
        'weather_temp_c' => null,
    ]);

    $this->artisan('weather:backfill')->assertSuccessful();
});

it('leaves the row null when the lookup still misses', function (): void {
    $this->mock(OpenMeteoClient::class)
        ->shouldReceive('fetchForActivity')
        ->once()
        ->andReturnNull();

    $detail = ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'start_date_local' => now()->subDays(30),
        'weather_temp_c' => null,
    ]);

    $this->artisan('weather:backfill')->assertSuccessful();

    expect($detail->fresh()->weather_temp_c)->toBeNull();
});

it('honors the --limit option', function (): void {
    $this->mock(OpenMeteoClient::class)
        ->shouldReceive('fetchForActivity')
        ->twice()
        ->andReturn(new WeatherSnapshot(tempC: 27, humidityPct: 80, rainDetected: false));

    Activity::factory()
        ->count(5)
        ->create()
        ->each(fn ($a) => ActivityDetail::factory()->for($a)->create([
            'start_lat' => -6.0,
            'start_lng' => 106.0,
            'start_date_local' => now()->subDays(30),
            'weather_temp_c' => null,
        ]));

    $this->artisan('weather:backfill', ['--limit' => 2])->assertSuccessful();
});

it('reaches a newer fillable row behind 200 unfillable ones and records each miss', function (): void {
    $this->mock(OpenMeteoClient::class)
        ->shouldReceive('fetchForActivity')
        ->andReturnUsing(fn (float $lat) => $lat === -1.0
            ? new WeatherSnapshot(tempC: 27, humidityPct: 80, rainDetected: false)
            : null);

    ActivityDetail::factory()->count(200)->create([
        'start_lat' => -6.0,
        'start_lng' => 106.0,
        'start_date_local' => now()->subDays(30),
        'weather_temp_c' => null,
    ]);
    $fillable = ActivityDetail::factory()->create([
        'start_lat' => -1.0,
        'start_lng' => 106.0,
        'start_date_local' => now()->subDays(30),
        'weather_temp_c' => null,
    ]);

    $this->artisan('weather:backfill')->assertSuccessful();
    expect($fillable->fresh()->weather_temp_c)->toBeNull();

    $this->artisan('weather:backfill')->assertSuccessful();

    expect($fillable->fresh()->weather_temp_c)->toBe(27)
        ->and(ActivityDetail::query()->where('weather_attempts', '>=', 1)->count())->toBe(200)
        ->and(ActivityDetail::query()->whereNotNull('weather_attempted_at')->count())->toBe(200);
});

it('stops retrying a row after five attempts', function (): void {
    $this->mock(OpenMeteoClient::class)->shouldReceive('fetchForActivity')->never();

    $exhausted = ActivityDetail::factory()->create([
        'start_lat' => -6.0,
        'start_lng' => 106.0,
        'start_date_local' => now()->subDays(30),
        'weather_temp_c' => null,
        'weather_attempts' => 5,
        'weather_attempted_at' => now()->subDays(3),
    ]);

    $this->artisan('weather:backfill')->assertSuccessful();

    expect($exhausted->fresh()->weather_attempts)->toBe(5);
});

it('tries the oldest attempt first among attempted rows', function (): void {
    $this->mock(OpenMeteoClient::class)
        ->shouldReceive('fetchForActivity')
        ->once()
        ->andReturn(new WeatherSnapshot(tempC: 27, humidityPct: 80, rainDetected: false));

    $recent = ActivityDetail::factory()->create([
        'start_lat' => -6.0, 'start_lng' => 106.0, 'start_date_local' => now()->subDays(30),
        'weather_temp_c' => null, 'weather_attempts' => 1, 'weather_attempted_at' => now()->subHour(),
    ]);
    $older = ActivityDetail::factory()->create([
        'start_lat' => -6.0, 'start_lng' => 106.0, 'start_date_local' => now()->subDays(30),
        'weather_temp_c' => null, 'weather_attempts' => 1, 'weather_attempted_at' => now()->subDays(2),
    ]);

    $this->artisan('weather:backfill', ['--limit' => 1])->assertSuccessful();

    expect($older->fresh()->weather_temp_c)->toBe(27)
        ->and($recent->fresh()->weather_temp_c)->toBeNull();
});

it('reports runs still missing weather 48 hours after ingest, but not newer or demo ones', function (): void {
    $this->mock(OpenMeteoClient::class)->shouldReceive('fetchForActivity')->never();
    $missing = [
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'start_date_local' => now()->subDays(30),
        'weather_temp_c' => null,
        'weather_attempts' => ActivityDetail::MAX_BACKFILL_ATTEMPTS,
    ];
    ActivityDetail::factory()->create([...$missing, 'created_at' => now()->subHours(48)]);
    ActivityDetail::factory()->create([...$missing, 'created_at' => now()->subHours(47)]);
    ActivityDetail::factory()->create([...$missing, 'start_lat' => null, 'start_lng' => null, 'created_at' => now()->subDays(5)]);
    ActivityDetail::factory()
        ->for(Activity::factory()->for(User::factory()->state(['is_demo' => true])))
        ->create([...$missing, 'created_at' => now()->subDays(5)]);

    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('persistentGap')->once()->with('weather:backfill', 'weather', 1);
    $this->app->instance(MaintainerAlerter::class, $alerter);

    $this->artisan('weather:backfill')->assertSuccessful();
});

it('reports no weather gap once every run has weather', function (): void {
    $this->mock(OpenMeteoClient::class)->shouldReceive('fetchForActivity')->never();
    ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'start_date_local' => now()->subDays(30),
        'weather_temp_c' => 25,
        'created_at' => now()->subDays(4),
    ]);

    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('persistentGap')->once()->with('weather:backfill', 'weather', 0);
    $this->app->instance(MaintainerAlerter::class, $alerter);

    $this->artisan('weather:backfill')->assertSuccessful();
});
