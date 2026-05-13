<?php

declare(strict_types=1);

use App\Services\Geo\NominatimResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
});

it('formats Indonesian address parts into a comma-joined display string', function (): void {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'address' => [
                'suburb' => 'Kebayoran Baru',
                'city' => 'Jakarta Selatan',
                'state' => 'DKI Jakarta',
                'country' => 'Indonesia',
                'country_code' => 'id',
            ],
        ]),
    ]);

    $resolver = new NominatimResolver();
    $result = $resolver->reverse(-6.24, 106.81);

    expect($result)->not->toBeNull();
    expect($result->name)->toBe('Kebayoran Baru, Jakarta Selatan, DKI Jakarta, Indonesia');
    expect($result->country)->toBe('ID');
});

it('returns null when the response is malformed', function (): void {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(['error' => 'bad input']),
    ]);

    $resolver = new NominatimResolver();
    expect($resolver->reverse(0.0, 0.0))->toBeNull();
});

it('returns null when the API call fails (non-200)', function (): void {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response('rate limited', 429),
    ]);

    expect((new NominatimResolver())->reverse(-6.2, 106.8))->toBeNull();
});

it('returns null when the API throws', function (): void {
    Http::fake([
        'nominatim.openstreetmap.org/*' => fn () => throw new RuntimeException('connect timeout'),
    ]);

    expect((new NominatimResolver())->reverse(-6.2, 106.8))->toBeNull();
});

it('caches consecutive calls for the same coords (rounded to ~110m)', function (): void {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'address' => ['city' => 'Bogor', 'country' => 'Indonesia', 'country_code' => 'id'],
        ]),
    ]);

    $resolver = new NominatimResolver();
    $resolver->reverse(-6.595, 106.8155);
    $resolver->reverse(-6.5951, 106.8156); // same 3-decimal grid

    Http::assertSentCount(1);
});

it('caches miss sentinels so a known-bad coord pair does not retry Nominatim', function (): void {
    // First call resolves to null (empty address); resolver writes
    // `false` into the cache. Second call short-circuits on the
    // sentinel without re-hitting Nominatim.
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(['address' => []]),
    ]);

    $resolver = new NominatimResolver();
    expect($resolver->reverse(0.0, 0.0))->toBeNull();
    expect($resolver->reverse(0.0, 0.0))->toBeNull();

    Http::assertSentCount(1);
});

it('returns null when there are no usable address fields', function (): void {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(['address' => []]),
    ]);

    expect((new NominatimResolver())->reverse(0.0, 0.0))->toBeNull();
});
