<?php

declare(strict_types=1);

use App\Models\StravaConnection;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use App\Services\Strava\StravaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.strava.client_id' => 'test-client-id',
        'services.strava.client_secret' => 'test-client-secret',
    ]);
});

it('returns the connection unchanged when token is comfortably valid', function (): void {
    Http::fake();

    $connection = StravaConnection::factory()->create([
        'access_token' => 'valid-access',
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    $result = (new StravaClient())->refreshIfExpired($connection);

    expect($result->access_token)->toBe('valid-access');
    Http::assertNothingSent();
});

it('refreshes the token when it has already expired', function (): void {
    $expiresAt = Carbon::now()->addHours(6)->startOfSecond();

    Http::fake([
        'strava.com/oauth/token' => Http::response([
            'access_token' => 'fresh-access',
            'refresh_token' => 'fresh-refresh',
            'expires_at' => $expiresAt->timestamp,
            'expires_in' => 21600,
            'token_type' => 'Bearer',
        ]),
    ]);

    $connection = StravaConnection::factory()->create([
        'access_token' => 'old-access',
        'refresh_token' => 'old-refresh',
        'token_expires_at' => Carbon::now()->subMinute(),
    ]);

    $result = (new StravaClient())->refreshIfExpired($connection);

    expect($result->access_token)->toBe('fresh-access')
        ->and($result->refresh_token)->toBe('fresh-refresh')
        ->and($result->token_expires_at->timestamp)->toBe($expiresAt->timestamp);

    Http::assertSent(fn ($request) => $request->url() === 'https://www.strava.com/oauth/token'
        && $request['client_id'] === 'test-client-id'
        && $request['client_secret'] === 'test-client-secret'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh');
});

it('refreshes when the token is within the 60-second buffer', function (): void {
    Http::fake([
        'strava.com/oauth/token' => Http::response([
            'access_token' => 'fresh-access',
            'refresh_token' => 'fresh-refresh',
            'expires_at' => Carbon::now()->addHours(6)->timestamp,
        ]),
    ]);

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->addSeconds(30),
    ]);

    (new StravaClient())->refreshIfExpired($connection);

    Http::assertSentCount(1);
    expect($connection->refresh()->access_token)->toBe('fresh-access');
});

it('skips the refresh POST when another worker already refreshed under the lock', function (): void {
    Http::fake();

    $connection = StravaConnection::factory()->create([
        'access_token' => 'stale-in-memory',
        'token_expires_at' => Carbon::now()->subMinute(),
    ]);

    // Simulate a concurrent worker that grabbed the lock first and refreshed:
    // the DB row is now fresh while our in-memory model still looks expired.
    // Use a separate model instance so encrypted casts are applied on write.
    StravaConnection::query()->find($connection->id)->update([
        'access_token' => 'refreshed-by-other-worker',
        'token_expires_at' => Carbon::now()->addHours(6),
    ]);

    $result = (new StravaClient())->refreshIfExpired($connection);

    expect($result->access_token)->toBe('refreshed-by-other-worker');
    Http::assertNothingSent();
});

it('throws when token refresh fails', function (): void {
    Http::fake([
        'strava.com/oauth/token' => Http::response(['message' => 'Bad refresh token'], 400),
    ]);

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->subMinute(),
    ]);

    expect(fn () => (new StravaClient())->refreshIfExpired($connection))
        ->toThrow(StravaTokenRefreshFailedException::class);
});

it('makes authenticated GET requests to the Strava API', function (): void {
    Http::fake([
        'www.strava.com/api/v3/athlete' => Http::response(['id' => 12345, 'firstname' => 'Ada']),
    ]);

    $connection = StravaConnection::factory()->create([
        'access_token' => 'live-access',
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    $response = (new StravaClient())->get($connection, 'athlete');

    expect($response->json('id'))->toBe(12345);

    Http::assertSent(fn ($request) => $request->url() === 'https://www.strava.com/api/v3/athlete'
        && $request->hasHeader('Authorization', 'Bearer live-access'));
});

it('refreshes the token before making a GET when it is expired', function (): void {
    Http::fake([
        'strava.com/oauth/token' => Http::response([
            'access_token' => 'fresh-access',
            'refresh_token' => 'fresh-refresh',
            'expires_at' => Carbon::now()->addHours(6)->timestamp,
        ]),
        'www.strava.com/api/v3/athlete' => Http::response(['id' => 12345]),
    ]);

    $connection = StravaConnection::factory()->create([
        'access_token' => 'expired-access',
        'token_expires_at' => Carbon::now()->subMinute(),
    ]);

    (new StravaClient())->get($connection, 'athlete');

    Http::assertSent(fn ($request) => $request->url() === 'https://www.strava.com/api/v3/athlete'
        && $request->hasHeader('Authorization', 'Bearer fresh-access'));
});

it('throws StravaRateLimitedException naming the exhausted bucket and retry-after seconds', function (): void {
    Http::fake();

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    for ($i = 0; $i < 200; $i++) {
        RateLimiter::hit("strava-api:{$connection->user_id}:15min", 15 * 60);
    }

    try {
        (new StravaClient())->get($connection, 'athlete');
        $this->fail('Expected StravaRateLimitedException to be thrown.');
    } catch (StravaRateLimitedException $e) {
        expect($e->getMessage())
            ->toMatch('/^Strava rate limit exhausted for bucket \[strava-api:' . $connection->user_id . ':15min\]; retry in \d+s\.$/');
    }

    Http::assertNothingSent();
});

it('records hits against both rate limit buckets per request', function (): void {
    Http::fake([
        'www.strava.com/api/v3/*' => Http::response(['ok' => true]),
    ]);

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    (new StravaClient())->get($connection, 'athlete');

    expect(RateLimiter::attempts("strava-api:{$connection->user_id}:15min"))->toBe(1)
        ->and(RateLimiter::attempts("strava-api:{$connection->user_id}:daily"))->toBe(1);
});
