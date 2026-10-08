<?php

declare(strict_types=1);

use App\Actions\Geo\ReverseGeocodeAction;
use App\Services\Geo\Exceptions\NominatimRateSlotUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    $resolver = new ReverseGeocodeAction();
    $result = $resolver(-6.24, 106.81);

    expect($result)->not->toBeNull();
    expect($result->name)->toBe('Kebayoran Baru, Jakarta Selatan, DKI Jakarta, Indonesia');
    expect($result->country)->toBe('ID');
});

it('returns null when the response is malformed', function (): void {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(['error' => 'bad input']),
    ]);

    $resolver = new ReverseGeocodeAction();
    expect($resolver(0.0, 0.0))->toBeNull();
});

it('returns null when the API call fails (non-200)', function (): void {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response('rate limited', 429),
    ]);

    expect(new ReverseGeocodeAction()(-6.2, 106.8))->toBeNull();
});

it('returns null when the API throws', function (): void {
    Http::fake([
        'nominatim.openstreetmap.org/*' => fn () => throw new RuntimeException('connect timeout'),
    ]);

    expect(new ReverseGeocodeAction()(-6.2, 106.8))->toBeNull();
});

it('logs a failed lookup without the coordinate its error message carries', function (): void {
    Log::spy();
    Http::fake(['nominatim.openstreetmap.org/*' => fn () => throw new ConnectionException(
        'cURL error 28: Operation timed out (see https://curl.se/libcurl/c/libcurl-errors.html) for https://nominatim.openstreetmap.org/reverse?lat=12.3456789&lon=98.7654321&format=jsonv2',
    )]);

    expect(new ReverseGeocodeAction()(12.3456789, 98.7654321))->toBeNull();

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'nominatim resolve failed'
            && array_keys($context) === ['error']
            && str_contains($context['error'], 'cURL error 28')
            && ! str_contains($context['error'], '12.3456789')
            && ! str_contains($context['error'], '98.7654321'));
});

it('returns cached locations without claiming another request slot', function (): void {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'address' => ['city' => 'Bogor', 'country' => 'Indonesia', 'country_code' => 'id'],
        ]),
    ]);

    $resolver = new ReverseGeocodeAction();
    $first = $resolver(-6.595, 106.8155);
    $lock = Cache::lock('geo:nominatim:request-slot-lock', 20);
    expect($lock->get())->toBeTrue();
    $second = $resolver(-6.5951, 106.8156);

    expect($second)->toEqual($first);
    Http::assertSentCount(1);
});

it('keeps cached locations in a serialized cache store', function (): void {
    config([
        'cache.default' => 'array',
        'cache.stores.array.serialize' => true,
    ]);
    Cache::forgetDriver('array');
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'address' => ['city' => 'Bogor', 'country' => 'Indonesia', 'country_code' => 'id'],
        ]),
    ]);

    $resolver = new ReverseGeocodeAction();
    expect($resolver(-6.24, 106.81)?->name)->toBe('Bogor, Indonesia')
        ->and($resolver(-6.24, 106.81)?->name)->toBe('Bogor, Indonesia');

    Http::assertSentCount(1);
});

it('paces uncached requests at least one second apart', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00.500000'));
    $requestTimes = [];
    Http::fake([
        'nominatim.openstreetmap.org/*' => function () use (&$requestTimes) {
            $requestTimes[] = Carbon::now();

            return Http::response([
                'address' => ['city' => 'Bogor', 'country' => 'Indonesia', 'country_code' => 'id'],
            ]);
        },
    ]);

    $resolver = new ReverseGeocodeAction();
    $resolver(-6.24, 106.81);
    expect(fn () => $resolver(-7.25, 112.75))
        ->toThrow(NominatimRateSlotUnavailableException::class);
    Carbon::setTestNow(Carbon::now()->addSecond());
    $resolver(-7.25, 112.75);

    expect($requestTimes)->toHaveCount(2)
        ->and($requestTimes[0]->diffInMicroseconds($requestTimes[1]))->toBeGreaterThanOrEqual(1_000_000);
});

it('caches an empty address so the same grid does not retry Nominatim', function (): void {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(['address' => []]),
    ]);

    $resolver = new ReverseGeocodeAction();
    expect($resolver(0.0, 0.0))->toBeNull();
    expect($resolver(0.0, 0.0))->toBeNull();

    Http::assertSentCount(1);
});

it('retries a transient failure after its ten-minute cache expires', function (): void {
    config(['cache.default' => 'array']);
    $this->freezeTime();
    Http::fakeSequence('nominatim.openstreetmap.org/*')
        ->push('rate limited', 429)
        ->push([
            'address' => ['city' => 'Bogor', 'country' => 'Indonesia', 'country_code' => 'id'],
        ]);

    $resolver = new ReverseGeocodeAction();
    expect($resolver(-6.2, 106.8))->toBeNull();
    expect($resolver->shouldSkipBackfill(-6.2, 106.8))->toBeTrue();
    expect($resolver(-6.2, 106.8))->toBeNull();
    Http::assertSentCount(1);

    $this->travel(601)->seconds();

    expect($resolver(-6.2, 106.8)?->name)->toBe('Bogor, Indonesia');
    expect($resolver->shouldSkipBackfill(-6.2, 106.8))->toBeFalse();
    Http::assertSentCount(2);
});

it('ignores the old cache key version', function (): void {
    Cache::put('geo:nominatim:-6.200:106.800', false, 2_592_000);
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'address' => ['city' => 'Bogor', 'country' => 'Indonesia', 'country_code' => 'id'],
        ]),
    ]);

    expect((new ReverseGeocodeAction())(-6.2, 106.8)?->name)->toBe('Bogor, Indonesia');
    Http::assertSentCount(1);
});
