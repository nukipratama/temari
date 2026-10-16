<?php

declare(strict_types=1);

use App\Enums\IntervalsIcuVerification;
use App\Services\IntervalsIcu\IntervalsIcuClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('verifies an athlete with basic auth under the API_KEY username', function (): void {
    Http::fake(['intervals.icu/*' => Http::response(['id' => 'i123456'])]);

    $result = new IntervalsIcuClient()->verify('i123456', 'secret-key');

    expect($result)->toBe(IntervalsIcuVerification::Verified);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://intervals.icu/api/v1/athlete/i123456'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('API_KEY:secret-key')));
});

it('reads an auth or not-found answer as a rejected ID and key', function (int $status): void {
    Http::fake(['intervals.icu/*' => Http::response(['error' => 'Auth failed'], $status)]);

    expect(new IntervalsIcuClient()->verify('i123456', 'wrong'))->toBe(IntervalsIcuVerification::Rejected);
})->with([401, 403, 404]);

it('reads a server error as unreachable rather than a bad key', function (): void {
    Http::fake(['intervals.icu/*' => Http::response('', 503)]);

    expect(new IntervalsIcuClient()->verify('i123456', 'secret-key'))->toBe(IntervalsIcuVerification::Unreachable);
});

it('reads a connection failure as unreachable', function (): void {
    Http::fake(['intervals.icu/*' => fn () => throw new ConnectionException('timed out')]);

    expect(new IntervalsIcuClient()->verify('i123456', 'secret-key'))->toBe(IntervalsIcuVerification::Unreachable);
});
