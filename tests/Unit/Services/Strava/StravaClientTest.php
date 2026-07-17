<?php

declare(strict_types=1);

use App\Models\StravaConnection;
use App\Services\Strava\Exceptions\StravaCircuitOpenException;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshTransientException;
use App\Services\Strava\StravaClient;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

    $result = new StravaClient()->refreshIfExpired($connection);

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

    $result = new StravaClient()->refreshIfExpired($connection);

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

    new StravaClient()->refreshIfExpired($connection);

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

    $result = new StravaClient()->refreshIfExpired($connection);

    expect($result->access_token)->toBe('refreshed-by-other-worker');
    Http::assertNothingSent();
});

it('throws a permanent refresh exception only on a 400 invalid_grant', function (): void {
    Http::fake([
        'strava.com/oauth/token' => Http::response(['message' => 'Bad refresh token'], 400),
    ]);

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->subMinute(),
    ]);

    expect(fn () => new StravaClient()->refreshIfExpired($connection))
        ->toThrow(StravaTokenRefreshFailedException::class);
});

it('throws a transient refresh exception on a non-400 refresh failure', function (int $status): void {
    Http::fake([
        'strava.com/oauth/token' => Http::response(['message' => 'transient'], $status),
    ]);

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->subMinute(),
    ]);

    expect(fn () => new StravaClient()->refreshIfExpired($connection))
        ->toThrow(StravaTokenRefreshTransientException::class);
})->with([
    '401 unauthorized' => [401],
    '429 rate limited' => [429],
    '500 server error' => [500],
    '503 unavailable' => [503],
]);

it('throws a transient refresh exception when the token endpoint times out', function (): void {
    Http::fake(function (): void {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->subMinute(),
    ]);

    expect(fn () => new StravaClient()->refreshIfExpired($connection))
        ->toThrow(StravaTokenRefreshTransientException::class);
});

it('makes authenticated GET requests to the Strava API', function (): void {
    Http::fake([
        'www.strava.com/api/v3/athlete' => Http::response(['id' => 12345, 'firstname' => 'Ada']),
    ]);

    $connection = StravaConnection::factory()->create([
        'access_token' => 'live-access',
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    $response = new StravaClient()->get($connection, 'athlete');

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

    new StravaClient()->get($connection, 'athlete');

    Http::assertSent(fn ($request) => $request->url() === 'https://www.strava.com/api/v3/athlete'
        && $request->hasHeader('Authorization', 'Bearer fresh-access'));
});

it('throws StravaConnectionRevokedException when the API rejects the token with 401', function (): void {
    Http::fake([
        'www.strava.com/api/v3/*' => Http::response([
            'message' => 'Authorization Error',
            'errors' => [['resource' => 'Athlete', 'field' => 'access_token', 'code' => 'invalid']],
        ], 401),
    ]);

    $connection = StravaConnection::factory()->create([
        'access_token' => 'rejected-access',
        'token_expires_at' => Carbon::now()->addHours(5), // clock-fresh, so no refresh
    ]);

    expect(fn () => new StravaClient()->get($connection, 'athlete'))
        ->toThrow(StravaConnectionRevokedException::class);
});

it('throws StravaRateLimitedException carrying the Retry-After delay on a real 429', function (): void {
    Http::fake([
        'www.strava.com/api/v3/*' => Http::response(['message' => 'Rate Limit Exceeded'], 429, ['Retry-After' => '42']),
    ]);

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    try {
        new StravaClient()->get($connection, 'athlete');
        $this->fail('Expected StravaRateLimitedException to be thrown.');
    } catch (StravaRateLimitedException $e) {
        expect($e->availableIn)->toBe(42);
    }
});

it('falls back to a null retry delay when a 429 omits Retry-After', function (): void {
    Http::fake([
        'www.strava.com/api/v3/*' => Http::response(['message' => 'Rate Limit Exceeded'], 429),
    ]);

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    try {
        new StravaClient()->get($connection, 'athlete');
        $this->fail('Expected StravaRateLimitedException to be thrown.');
    } catch (StravaRateLimitedException $e) {
        expect($e->availableIn)->toBeNull();
    }
});

it('does NOT move the breaker on a 429 (Strava is up, just busy)', function (): void {
    Http::fake([
        'www.strava.com/api/v3/*' => Http::response([], 429),
    ]);

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    expect(fn () => new StravaClient()->get($connection, 'athlete'))
        ->toThrow(StravaRateLimitedException::class);
    expect(new AppConfig()->integer(AppConfigKey::StravaBreakerFailures))->toBe(0);
});

it('throws StravaRateLimitedException naming the exhausted bucket and retry-after seconds', function (): void {
    Http::fake();

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    for ($i = 0; $i < 200; $i++) {
        RateLimiter::hit('strava-api:15min', 15 * 60);
    }

    try {
        new StravaClient()->get($connection, 'athlete');
        $this->fail('Expected StravaRateLimitedException to be thrown.');
    } catch (StravaRateLimitedException $e) {
        expect($e->getMessage())
            ->toMatch('/^Strava rate limit exhausted for bucket \[strava-api:15min\]; retry in \d+s\.$/');
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

    new StravaClient()->get($connection, 'athlete');

    expect(RateLimiter::attempts('strava-api:15min'))->toBe(1)
        ->and(RateLimiter::attempts('strava-api:daily'))->toBe(1);
});

it('shares one rate-limit budget across all athletes (per client, not per athlete)', function (): void {
    Http::fake([
        'www.strava.com/api/v3/*' => Http::response(['ok' => true]),
    ]);

    $athleteA = StravaConnection::factory()->create(['token_expires_at' => Carbon::now()->addHours(5)]);
    $athleteB = StravaConnection::factory()->create(['token_expires_at' => Carbon::now()->addHours(5)]);

    new StravaClient()->get($athleteA, 'athlete');
    new StravaClient()->get($athleteB, 'athlete');

    // Both athletes' calls land in the same shared bucket: Strava's limit is
    // per client, so two athletes consume two of the app's 200/15min, not one each.
    expect(RateLimiter::attempts('strava-api:15min'))->toBe(2)
        ->and(RateLimiter::attempts('strava-api:daily'))->toBe(2);
});

it('counts a 5xx toward the circuit breaker, then surfaces the request exception', function (): void {
    Http::fake([
        'www.strava.com/api/v3/*' => Http::response(['error' => 'boom'], 503),
    ]);

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    expect(fn () => new StravaClient()->get($connection, 'athlete'))
        ->toThrow(RequestException::class);
    expect(new AppConfig()->integer(AppConfigKey::StravaBreakerFailures))->toBe(1);
});

it('short-circuits with StravaCircuitOpenException and makes no HTTP call when the breaker is open', function (): void {
    $config = new AppConfig();
    $config->set(AppConfigKey::StravaBreakerState, 'open');
    $config->set(AppConfigKey::StravaBreakerOpenedAt, Carbon::now()->toIso8601String());

    Http::fake();
    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    expect(fn () => new StravaClient()->get($connection, 'athlete'))
        ->toThrow(StravaCircuitOpenException::class);
    Http::assertNothingSent();
});

it('does NOT move the breaker on a 401 (auth, not an outage)', function (): void {
    Http::fake([
        'www.strava.com/api/v3/*' => Http::response([], 401),
    ]);

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    expect(fn () => new StravaClient()->get($connection, 'athlete'))
        ->toThrow(StravaConnectionRevokedException::class);
    expect(new AppConfig()->integer(AppConfigKey::StravaBreakerFailures))->toBe(0);
});

it('resets the breaker failure streak on a successful 2xx', function (): void {
    new AppConfig()->set(AppConfigKey::StravaBreakerFailures, 3);

    Http::fake([
        'www.strava.com/api/v3/*' => Http::response(['ok' => true]),
    ]);

    $connection = StravaConnection::factory()->create([
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    new StravaClient()->get($connection, 'athlete');

    expect(new AppConfig()->integer(AppConfigKey::StravaBreakerFailures))->toBe(0);
});
