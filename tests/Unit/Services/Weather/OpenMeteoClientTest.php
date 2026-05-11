<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use App\Services\Weather\OpenMeteoClient;
use App\Services\Weather\WeatherSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    CarbonImmutable::setTestNow('2026-05-11 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('hits the forecast endpoint for recent activities', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:30:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T05:00', '2026-05-10T06:00', '2026-05-10T07:00'],
                'temperature_2m' => [25.0, 27.2, 29.5],
                'relative_humidity_2m' => [82, 78, 70],
                'precipitation' => [0, 0, 0],
            ],
        ]),
    ]);

    $snap = (new OpenMeteoClient())->fetchForActivity(-6.2, 106.8, $startedAt);

    expect($snap)->toBeInstanceOf(WeatherSnapshot::class)
        ->and($snap->tempC)->toBe(27)
        ->and($snap->humidityPct)->toBe(78)
        ->and($snap->rainDetected)->toBeFalse();

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), 'api.open-meteo.com/v1/forecast'));
});

it('hits the archive endpoint for activities older than 7 days', function (): void {
    $startedAt = CarbonImmutable::parse('2026-04-26 16:20:00');
    Http::fake([
        'archive-api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-04-26T15:00', '2026-04-26T16:00', '2026-04-26T17:00'],
                'temperature_2m' => [29, 30, 31],
                'relative_humidity_2m' => [65, 68, 71],
                'precipitation' => [0, 0, 0],
            ],
        ]),
    ]);

    $snap = (new OpenMeteoClient())->fetchForActivity(-6.2, 106.8, $startedAt);

    expect($snap)->toBeInstanceOf(WeatherSnapshot::class)
        ->and($snap->tempC)->toBe(30);

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), 'archive-api.open-meteo.com/v1/archive'));
});

it('buckets the start time down to the containing hour', function (): void {
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

    $snap = (new OpenMeteoClient())->fetchForActivity(-6.2, 106.8, $startedAt);

    // Should pick 14:00 bucket, not 15:00
    expect($snap?->tempC)->toBe(28);
});

it('detects rain when precipitation exceeds 0.1mm', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T06:00'],
                'temperature_2m' => [24],
                'relative_humidity_2m' => [95],
                'precipitation' => [2.4],
            ],
        ]),
    ]);

    $snap = (new OpenMeteoClient())->fetchForActivity(-6.2, 106.8, $startedAt);

    expect($snap?->rainDetected)->toBeTrue();
});

it('does NOT flag rain at exactly the 0.1 threshold', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => ['2026-05-10T06:00'],
                'temperature_2m' => [24],
                'relative_humidity_2m' => [80],
                'precipitation' => [0.1],
            ],
        ]),
    ]);

    $snap = (new OpenMeteoClient())->fetchForActivity(-6.2, 106.8, $startedAt);

    expect($snap?->rainDetected)->toBeFalse();
});

it('returns null on HTTP failure (pipeline keeps moving)', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response(['error' => 'down'], 500),
    ]);

    expect((new OpenMeteoClient())->fetchForActivity(-6.2, 106.8, $startedAt))->toBeNull();
});

it('returns null when the response shape is missing hourly', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake([
        'api.open-meteo.com/*' => Http::response(['something' => 'else']),
    ]);

    expect((new OpenMeteoClient())->fetchForActivity(-6.2, 106.8, $startedAt))->toBeNull();
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

    expect((new OpenMeteoClient())->fetchForActivity(-6.2, 106.8, $startedAt))->toBeNull();
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
    $first = $client->fetchForActivity(-6.2, 106.8, $startedAt);
    $second = $client->fetchForActivity(-6.2, 106.8, $startedAt);

    expect($first?->tempC)->toBe(27)->and($second?->tempC)->toBe(27);
    Http::assertSentCount(1);
});

it('returns null when the http client throws (timeout / connection failure)', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-10 06:00:00');
    Http::fake(function (): void {
        throw new ConnectionException('connection refused');
    });

    expect((new OpenMeteoClient())->fetchForActivity(-6.2, 106.8, $startedAt))->toBeNull();
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

    expect((new OpenMeteoClient())->fetchForActivity(-6.2, 106.8, $startedAt))->toBeNull();
});

it('distinguishes cache keys by hour-bucket', function (): void {
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
    $earlier = $client->fetchForActivity(-6.2, 106.8, CarbonImmutable::parse('2026-05-10 06:30:00'));
    $later = $client->fetchForActivity(-6.2, 106.8, CarbonImmutable::parse('2026-05-10 07:15:00'));

    expect($earlier?->tempC)->toBe(26)->and($later?->tempC)->toBe(28);
    Http::assertSentCount(2);
});
