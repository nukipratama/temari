<?php

declare(strict_types=1);

use App\Models\ActivityDetail;
use App\Services\Weather\OpenMeteoClient;
use App\Services\Weather\WeatherSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('corrects a forecast-sourced row once it is old enough for the archive', function (): void {
    $this->mock(OpenMeteoClient::class)
        ->shouldReceive('fetchArchive')
        ->once()
        ->andReturn(new WeatherSnapshot(
            tempC: 24,
            humidityPct: 91,
            rainDetected: true,
            windSpeedKmh: 12,
            windGustKmh: 20,
            windDirectionDeg: 180,
            rainIsForecast: false,
        ));

    $detail = ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'start_date_local' => now()->subDays(10),
        'weather_temp_c' => 30,
        'weather_rain_detected' => false,
        'weather_rain_is_forecast' => true,
    ]);

    $this->artisan('weather:correct-forecast')->assertSuccessful();

    $detail->refresh();
    expect($detail->weather_temp_c)->toBe(24)
        ->and($detail->weather_humidity_pct)->toBe(91)
        ->and($detail->weather_rain_detected)->toBeTrue()
        ->and($detail->weather_wind_speed_kmh)->toBe(12)
        ->and($detail->weather_wind_gust_kmh)->toBe(20)
        ->and($detail->weather_wind_direction_deg)->toBe(180)
        ->and($detail->weather_rain_is_forecast)->toBeFalse();
});

it('skips a forecast-sourced row that is not old enough yet', function (): void {
    $this->mock(OpenMeteoClient::class)
        ->shouldReceive('fetchArchive')
        ->never();

    $detail = ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'start_date_local' => now()->subDays(3),
        'weather_rain_is_forecast' => true,
    ]);

    $this->artisan('weather:correct-forecast')->assertSuccessful();

    expect($detail->fresh()->weather_rain_is_forecast)->toBeTrue();
});

it('skips a row that is already observed (not forecast-sourced)', function (): void {
    $this->mock(OpenMeteoClient::class)
        ->shouldReceive('fetchArchive')
        ->never();

    ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'start_date_local' => now()->subDays(10),
        'weather_rain_is_forecast' => false,
    ]);

    $this->artisan('weather:correct-forecast')->assertSuccessful();
});

it('leaves the row unchanged when the archive fetch still misses', function (): void {
    $this->mock(OpenMeteoClient::class)
        ->shouldReceive('fetchArchive')
        ->once()
        ->andReturnNull();

    $detail = ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'start_date_local' => now()->subDays(10),
        'weather_temp_c' => 30,
        'weather_rain_is_forecast' => true,
    ]);

    $this->artisan('weather:correct-forecast')->assertSuccessful();

    $detail->refresh();
    expect($detail->weather_temp_c)->toBe(30)
        ->and($detail->weather_rain_is_forecast)->toBeTrue();
});

it('does not touch RunCard badges when correcting weather', function (): void {
    $this->mock(OpenMeteoClient::class)
        ->shouldReceive('fetchArchive')
        ->once()
        ->andReturn(new WeatherSnapshot(tempC: 24, humidityPct: 91, rainDetected: false, rainIsForecast: false));

    $detail = ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'start_date_local' => now()->subDays(10),
        'weather_rain_detected' => true,
        'weather_rain_is_forecast' => true,
        'vibe_state' => 'nyala',
    ]);

    $this->artisan('weather:correct-forecast')->assertSuccessful();

    // The badge/vibe-derived state is untouched even though rainDetected flips.
    expect($detail->fresh()->vibe_state)->toBe('nyala');
});

it('does not let a permanently-uncorrectable old row starve fresher rows', function (): void {
    // The archive still misses for the very old row (uncorrectable) but has a value
    // for the newer one. Under the old ASC ordering + a tight limit, the stuck old
    // row would sit at the head every run and the newer row would never correct.
    $this->mock(OpenMeteoClient::class)
        ->shouldReceive('fetchArchive')
        ->andReturnUsing(fn ($lat, $lng, CarbonImmutable $startedAt): ?WeatherSnapshot => $startedAt->isBefore(now()->subDays(30))
            ? null
            : new WeatherSnapshot(tempC: 24, humidityPct: 90, rainDetected: false, rainIsForecast: false));

    $stuckOld = ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'start_date_local' => now()->subDays(60),
        'weather_rain_is_forecast' => true,
    ]);
    $fresher = ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'start_date_local' => now()->subDays(10),
        'weather_rain_is_forecast' => true,
    ]);

    // Limit of 1: only the head row is handled per run, so ordering decides who wins.
    $this->artisan('weather:correct-forecast', ['--limit' => 1])->assertSuccessful();

    expect($fresher->fresh()->weather_rain_is_forecast)->toBeFalse()
        ->and($stuckOld->fresh()->weather_rain_is_forecast)->toBeTrue();
});

it('honors the --limit option', function (): void {
    $this->mock(OpenMeteoClient::class)
        ->shouldReceive('fetchArchive')
        ->twice()
        ->andReturn(new WeatherSnapshot(tempC: 24, humidityPct: 91, rainDetected: false, rainIsForecast: false));

    ActivityDetail::factory()
        ->count(5)
        ->create([
            'start_lat' => -6.0,
            'start_lng' => 106.0,
            'start_date_local' => now()->subDays(10),
            'weather_rain_is_forecast' => true,
        ]);

    $this->artisan('weather:correct-forecast', ['--limit' => 2])->assertSuccessful();
});
