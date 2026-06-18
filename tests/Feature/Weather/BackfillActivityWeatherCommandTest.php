<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
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
