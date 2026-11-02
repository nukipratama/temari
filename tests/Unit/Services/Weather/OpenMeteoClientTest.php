<?php

declare(strict_types=1);

use App\Exceptions\Weather\OpenMeteoUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use App\Services\Weather\OpenMeteoClient;
use App\Services\Weather\WeatherSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Cache::flush();
    CarbonImmutable::setTestNow('2026-05-11 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('hits the forecast endpoint for recent activities', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T05:00', '2026-05-10T06:00', '2026-05-10T07:00'],
                'temperature_2m' => [25.0, 27.2, 29.5],
                'relative_humidity_2m' => [82, 78, 70],
                'precipitation' => [0, 0, 0],
                'wind_speed_10m' => [10.4, 27.6, 15.0],
                'wind_gusts_10m' => [18.2, 41.9, 22.0],
                'wind_direction_10m' => [90, 133.6, 180],
            ],
        ]),
    ]);

    $snap = new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0);

    expect($snap)->toBeInstanceOf(WeatherSnapshot::class)
        ->and($snap?->tempC)->toBe(27)
        ->and($snap?->humidityPct)->toBe(78)
        ->and($snap?->rainDetected)->toBeFalse()
        ->and($snap?->windSpeedKmh)->toBe(28)
        ->and($snap?->windGustKmh)->toBe(42)
        ->and($snap?->windDirectionDeg)->toBe(134)
        // forecast endpoint => rain flag is uncertain
        ->and($snap?->rainIsForecast)->toBeTrue();

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), 'api.open-meteo.com/v1/forecast')
        && str_contains(urldecode((string) $req->url()), 'wind_speed_10m'));
});

it('hits the archive endpoint for activities older than 7 days', function (): void {
    $startedAt = CarbonImmutable::parse('2026-04-26 16:00:00');
    Http::fake([
        'archive-api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-04-26T15:00', '2026-04-26T16:00', '2026-04-26T17:00'],
                'temperature_2m' => [29, 30, 31],
                'relative_humidity_2m' => [65, 68, 71],
                'precipitation' => [0, 0, 0],
                'wind_speed_10m' => [12, 24, 18],
                'wind_gusts_10m' => [20, 36, 28],
                'wind_direction_10m' => [45, 270, 90],
            ],
        ]),
    ]);

    $snap = new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0);

    expect($snap)->toBeInstanceOf(WeatherSnapshot::class)
        ->and($snap?->tempC)->toBe(30)
        ->and($snap?->windSpeedKmh)->toBe(24)
        ->and($snap?->windDirectionDeg)->toBe(270)
        // archive endpoint => rain flag is observed, not a forecast
        ->and($snap?->rainIsForecast)->toBeFalse();

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), 'archive-api.open-meteo.com/v1/archive'));
});

it('hits the forecast endpoint at exactly the 7-day boundary', function (): void {
    $startedAt = CarbonImmutable::now()->subDays(7);
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => [$startedAt->format('Y-m-d\TH:00')],
                'temperature_2m' => [25.0],
                'relative_humidity_2m' => [80],
                'precipitation' => [0],
                'wind_speed_10m' => [10.0],
                'wind_gusts_10m' => [15.0],
                'wind_direction_10m' => [90],
            ],
        ]),
    ]);

    $snap = new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0);

    expect($snap)->toBeInstanceOf(WeatherSnapshot::class);
    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), 'api.open-meteo.com/v1/forecast'));
});

it('hits the archive endpoint just past the 7-day boundary (8 days)', function (): void {
    $startedAt = CarbonImmutable::now()->subDays(8);
    Http::fake([
        'archive-api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => [$startedAt->format('Y-m-d\TH:00')],
                'temperature_2m' => [25.0],
                'relative_humidity_2m' => [80],
                'precipitation' => [0],
                'wind_speed_10m' => [10.0],
                'wind_gusts_10m' => [15.0],
                'wind_direction_10m' => [90],
            ],
        ]),
    ]);

    $snap = new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0);

    expect($snap)->toBeInstanceOf(WeatherSnapshot::class);
    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), 'archive-api.open-meteo.com/v1/archive'));
});

it('fetchArchive forces the archive endpoint and returns an observed (non-forecast) snapshot', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:30:00'); // within the forecast window, but forced to archive
    Http::fake([
        'archive-api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T06:00'],
                'temperature_2m' => [26],
                'relative_humidity_2m' => [88],
                'precipitation' => [0],
                'wind_speed_10m' => [14],
                'wind_gusts_10m' => [22],
                'wind_direction_10m' => [200],
            ],
        ]),
    ]);

    $snap = new OpenMeteoClient()->fetchArchive(-6.2, 106.8, $startedAt, 0);

    expect($snap)->toBeInstanceOf(WeatherSnapshot::class)
        ->and($snap?->tempC)->toBe(26)
        ->and($snap?->rainIsForecast)->toBeFalse();

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), 'archive-api.open-meteo.com/v1/archive'));
});

it('interpolates a start between the hours around it', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 14:37:00'); // not on the hour
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T14:00', '2026-05-10T15:00'],
                'temperature_2m' => [28, 30],
                'relative_humidity_2m' => [70, 75],
                'precipitation' => [0, 0],
            ],
        ]),
    ]);

    $snap = new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0);

    // 37 minutes into a 28→30 hour reads 29.2, not the 14:00 value
    expect($snap?->tempC)->toBe(29);
});

it('detects rain when precipitation exceeds 0.1mm', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T06:00', '2026-05-10T07:00'],
                'temperature_2m' => [24, 24],
                'relative_humidity_2m' => [95, 95],
                'precipitation' => [0, 2.4],
            ],
        ]),
    ]);

    $snap = new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0);

    expect($snap?->rainDetected)->toBeTrue();
});

it('does NOT flag rain at exactly the 0.1 threshold', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T06:00', '2026-05-10T07:00'],
                'temperature_2m' => [24, 24],
                'relative_humidity_2m' => [80, 80],
                'precipitation' => [0, 0.1],
            ],
        ]),
    ]);

    $snap = new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0);

    expect($snap?->rainDetected)->toBeFalse();
});

it('returns null on HTTP failure (pipeline keeps moving)', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response(['error' => 'down'], 500),
    ]);

    expect(new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0))->toBeNull();
});

it('logs a warning with status and hour but no coordinate when the response fails', function (): void {
    $log = Log::spy();
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake(['api.open-meteo.com/*' => Http::response(['error' => 'down'], 503)]);

    expect(new OpenMeteoClient()->fetchForActivity(12.3456789, 98.7654321, $startedAt, 0))->toBeNull();

    $log->shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'open-meteo request failed'
            && $context === ['status' => 503, 'hour' => '2026-05-10T06:00']);
});

it('reports a rate limit as Open-Meteo unavailable so a batch can stop', function (): void {
    Http::fake(['api.open-meteo.com/*' => Http::response(['error' => 'rate limited'], 429)]);

    expect(fn () => new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, CarbonImmutable::parse('2026-05-10 06:00:00'), 0))
        ->toThrow(OpenMeteoUnavailableException::class, 'open-meteo rate limited (429)');
});

it('reports a timeout as Open-Meteo unavailable without the coordinate its error message carries', function (): void {
    Http::fake(['api.open-meteo.com/*' => fn () => throw new ConnectionException(
        'cURL error 28: Operation timed out (see https://curl.se/libcurl/c/libcurl-errors.html) for https://api.open-meteo.com/v1/forecast?latitude=12.3456789&longitude=98.7654321&hourly=temperature_2m',
    )]);

    try {
        new OpenMeteoClient()->fetchForActivity(12.3456789, 98.7654321, CarbonImmutable::parse('2026-05-10 06:00:00'), 0);
        $this->fail('Expected OpenMeteoUnavailableException');
    } catch (OpenMeteoUnavailableException $e) {
        expect($e->getMessage())->toContain('cURL error 28')
            ->and(str_contains($e->getMessage(), '12.3456789'))->toBeFalse()
            ->and(str_contains($e->getMessage(), '98.7654321'))->toBeFalse()
            ->and($e->getPrevious())->toBeNull();
    }
});

it('returns null when the response shape is missing hourly', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response(['something' => 'else']),
    ]);

    expect(new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0))->toBeNull();
});

it('returns null when the activity hour is not in the response', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T08:00'],
                'temperature_2m' => [28],
                'relative_humidity_2m' => [70],
                'precipitation' => [0],
            ],
        ]),
    ]);

    expect(new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0))->toBeNull();
});

it('caches and short-circuits the second call', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T06:00'],
                'temperature_2m' => [27],
                'relative_humidity_2m' => [80],
                'precipitation' => [0],
            ],
        ]),
    ]);

    $client = new OpenMeteoClient();
    $first = $client->fetchForActivity(-6.2, 106.8, $startedAt, 0);
    $second = $client->fetchForActivity(-6.2, 106.8, $startedAt, 0);

    expect($first?->tempC)->toBe(27)->and($second?->tempC)->toBe(27);
    Http::assertSentCount(1);
});

it('returns null when the http client throws something other than a connection failure', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake(function (): void {
        throw new RuntimeException('unexpected');
    });

    expect(new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0))->toBeNull();
});

it('returns null when the matched hour bucket has null temp or humidity', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T06:00'],
                'temperature_2m' => [null],
                'relative_humidity_2m' => [80],
                'precipitation' => [0],
            ],
        ]),
    ]);

    expect(new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0))->toBeNull();
});

it('refetches when the cached value is a legacy non-array (self-heals poisoned keys)', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    // Simulate a poisoned cache entry left by an older runtime: a bare object
    // where the array shape is now expected. It must NOT blow up the return type.
    $key = 'weather:v3:-6.200:106.800:2026-05-10T06:00:0';
    Cache::put($key, new WeatherSnapshot(tempC: 99, humidityPct: 1, rainDetected: true), 3600);

    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T06:00'],
                'temperature_2m' => [27],
                'relative_humidity_2m' => [80],
                'precipitation' => [0],
            ],
        ]),
    ]);

    $snap = new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0);

    expect($snap)->toBeInstanceOf(WeatherSnapshot::class)->and($snap?->tempC)->toBe(27);
    Http::assertSentCount(1);
});

it('round-trips the cached array shape back into a WeatherSnapshot', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    $key = 'weather:v3:-6.200:106.800:2026-05-10T06:00:0';
    Cache::put($key, ['t' => 21, 'tm' => 23, 'h' => 64, 'dp' => 14, 'at' => 20, 'r' => true, 'ws' => 24, 'wg' => 38, 'wd' => 200, 'rf' => true], 3600);

    Http::fake(); // any HTTP call would fail the assertion below

    $snap = new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 0);

    expect($snap)->toBeInstanceOf(WeatherSnapshot::class)
        ->and($snap?->tempC)->toBe(21)
        ->and($snap?->humidityPct)->toBe(64)
        ->and($snap?->rainDetected)->toBeTrue()
        ->and($snap?->windSpeedKmh)->toBe(24)
        ->and($snap?->windGustKmh)->toBe(38)
        ->and($snap?->windDirectionDeg)->toBe(200)
        ->and($snap?->rainIsForecast)->toBeTrue()
        ->and($snap?->tempMaxC)->toBe(23)
        ->and($snap?->dewPointC)->toBe(14)
        ->and($snap?->apparentTempC)->toBe(20);
    Http::assertNothingSent();
});

it('distinguishes cache keys by start minute', function (): void {
    Http::fake([
        'api.open-meteo.com/*' => Http::sequence()
            ->push([
                'hourly' => [
                    'time' => ['2026-05-10T06:00', '2026-05-10T07:00'],
                    'temperature_2m' => [26, 28],
                    'relative_humidity_2m' => [80, 75],
                    'precipitation' => [0, 0],
                ],
            ])
            ->push([
                'hourly' => [
                    'time' => ['2026-05-10T06:00', '2026-05-10T07:00'],
                    'temperature_2m' => [26, 28],
                    'relative_humidity_2m' => [80, 75],
                    'precipitation' => [0, 0],
                ],
            ]),
    ]);

    $client = new OpenMeteoClient();
    $earlier = $client->fetchForActivity(-6.2, 106.8, CarbonImmutable::parse('2026-05-10 06:30:00'), 0);
    $later = $client->fetchForActivity(-6.2, 106.8, CarbonImmutable::parse('2026-05-10 07:15:00'), 0);

    expect($earlier?->tempC)->toBe(27)->and($later?->tempC)->toBe(28);
    Http::assertSentCount(2);
});

it('averages conditions over the run and keeps the peak temperature', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 07:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T07:00', '2026-05-10T08:00'],
                'temperature_2m' => [26, 30],
                'relative_humidity_2m' => [90, 70],
                'dew_point_2m' => [24, 22],
                'apparent_temperature' => [30, 36],
                'precipitation' => [0, 0],
                'wind_speed_10m' => [10, 20],
                'wind_gusts_10m' => [15, 35],
                'wind_direction_10m' => [90, 180],
            ],
        ]),
    ]);

    $snap = new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 3600);

    expect($snap?->tempC)->toBe(28)
        ->and($snap?->tempMaxC)->toBe(30)
        ->and($snap?->humidityPct)->toBe(80)
        ->and($snap?->dewPointC)->toBe(23)
        ->and($snap?->apparentTempC)->toBe(33)
        ->and($snap?->windSpeedKmh)->toBe(15)
        ->and($snap?->windGustKmh)->toBe(35)
        // midpoint 07:30 rounds to the 08:00 bearing rather than averaging 90° and 180°
        ->and($snap?->windDirectionDeg)->toBe(180);
});

it('flags rain that falls anywhere during the run but not before it', function (string $endsAt, array $precipitation, bool $expected): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 07:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T07:00', '2026-05-10T08:00', '2026-05-10T09:00'],
                'temperature_2m' => [26, 27, 28],
                'relative_humidity_2m' => [80, 80, 80],
                'precipitation' => $precipitation,
            ],
        ]),
    ]);

    $elapsed = (int) $startedAt->diffInSeconds(CarbonImmutable::parse($endsAt));
    $snap = new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, $elapsed);

    expect($snap?->rainDetected)->toBe($expected);
})->with([
    'shower in the run\'s second hour' => ['2026-05-10 08:30:00', [0, 0, 1.2], true],
    'rain only in the hour before the start' => ['2026-05-10 08:00:00', [3.0, 0, 0], false],
    'rain only after the finish' => ['2026-05-10 08:00:00', [0, 0, 1.2], false],
]);

it('asks the archive for every local date the run spans', function (): void {
    $startedAt = CarbonImmutable::parse('2026-04-26 23:30:00');
    Http::fake([
        'archive-api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-04-26T23:00', '2026-04-27T00:00'],
                'temperature_2m' => [25, 24],
                'relative_humidity_2m' => [85, 88],
                'precipitation' => [0, 0],
            ],
        ]),
    ]);

    $snap = new OpenMeteoClient()->fetchForActivity(-6.2, 106.8, $startedAt, 3600);

    expect($snap?->tempC)->toBe(24);
    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), 'start_date=2026-04-26')
        && str_contains((string) $req->url(), 'end_date=2026-04-27'));
});
