<?php

declare(strict_types=1);

namespace App\Services\Strava;

use App\Enums\StravaReadPriority;
use App\Models\StravaConnection;
use App\Services\Strava\Exceptions\StravaCircuitOpenException;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshTransientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pulse\Facades\Pulse;
use Throwable;

class StravaClient
{
    private const string TOKEN_URL = 'https://www.strava.com/oauth/token';

    private const string DEAUTHORIZE_URL = 'https://www.strava.com/oauth/deauthorize';

    private const int REFRESH_BUFFER_SECONDS = 60;

    private const int REFRESH_LOCK_SECONDS = 15;

    // Strava enforces rate limits per CLIENT (the whole app), not per athlete, so
    // these buckets are keyed globally and shared across every connected user. The
    // values are this app's own Read allocation per its Strava API dashboard
    // (200 / 15min, 2000 / day), not the lower 100 / 1000 default the public
    // docs quote; they bind before the Overall limits (400 / 4000) because all
    // of our calls are reads.
    private const int RATE_LIMIT_15MIN_MAX = 200;

    private const int RATE_LIMIT_15MIN_DECAY = 15 * 60;

    private const int RATE_LIMIT_DAILY_MAX = 2000;

    private const int RATE_LIMIT_DAILY_DECAY = 24 * 60 * 60;

    // Share of the 15-minute bucket only StravaReadPriority::Live may spend. A
    // freshly-finished run appearing promptly is the product's core promise; a
    // user scrolling their 2019 archive can wait for the bucket to roll over.
    // The daily bucket reserves a flat floor instead (`strava.live_read_floor`).
    private const int LIVE_RESERVE_PERCENT = 25;

    public function __construct(private readonly ?StravaCircuitBreaker $breaker = null)
    {
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(
        StravaConnection $connection,
        string $path,
        array $query = [],
        StravaReadPriority $priority = StravaReadPriority::Live,
    ): Response {
        $breaker = $this->breaker();
        if (! $breaker->allowsRequest()) {
            throw new StravaCircuitOpenException(
                "Strava circuit breaker is open; skipped request to [{$path}].",
            );
        }

        $connection = $this->refreshIfExpired($connection);

        $this->guardRateLimit($priority);

        try {
            $response = Http::baseUrl(self::apiBaseUrl())
                ->withToken($connection->access_token)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            // Transport failure / timeout: Strava is unreachable — count it.
            $breaker->recordFailure();

            throw $e;
        }

        if ($response->status() === 401) {
            // 401 is a per-connection auth problem, not a Strava outage: leave the
            // breaker untouched and surface it so the caller revokes the token.
            throw new StravaConnectionRevokedException(
                "Strava rejected the access token with 401 for [{$path}].",
            );
        }

        if ($response->status() === 429) {
            // A real Strava 429 (server-side bucket exhausted, distinct from our
            // local guardRateLimit): surface it as a rate-limit so the caller's
            // ThrottlesExceptions middleware absorbs it as a backoff rather than a
            // generic failure. Don't move the breaker — Strava is up, just busy.
            throw new StravaRateLimitedException(
                "Strava returned 429 for [{$path}]; backing off.",
                $this->retryAfterSeconds($response),
            );
        }

        if ($response->serverError()) {
            // 5xx: Strava itself is failing — count toward the breaker.
            $breaker->recordFailure();

            return $response->throw();
        }

        // 2xx (or a non-5xx 4xx like 404) means Strava is reachable and healthy.
        $breaker->recordSuccess();

        return $response->throw();
    }

    /**
     * Hands the athlete's grant back to Strava, which is what frees their slot
     * against the app's athlete allocation — until this is called, an account
     * deleted here still occupies one on Strava's side.
     *
     * Total by design: every caller is on a path that is going to finish
     * (a deletion, an operator freeing a slot) whether or not Strava answers,
     * so a failure is reported as `false` and logged rather than thrown.
     *
     * Deliberately outside {@see self::get()}'s gauntlet, the same way the
     * webhook subscription calls are: it spends none of the read budget those
     * buckets meter, and a refused revocation says nothing about whether
     * Strava is healthy, so it must not move the circuit breaker either.
     */
    public function deauthorize(StravaConnection $connection): bool
    {
        try {
            $connection = $this->refreshIfExpired($connection);

            $response = Http::asForm()->post(self::DEAUTHORIZE_URL, [
                'access_token' => $connection->access_token,
            ]);
        } catch (Throwable $e) {
            Log::warning('strava deauthorize could not be delivered', [
                'user_id' => $connection->user_id,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('strava refused the deauthorize', [
                'user_id' => $connection->user_id,
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }

    public static function apiBaseUrl(): string
    {
        return rtrim((string) config('services.strava.api_base_url'), '/');
    }

    private function breaker(): StravaCircuitBreaker
    {
        return $this->breaker ?? app(StravaCircuitBreaker::class);
    }

    /**
     * Seconds to wait per Strava's Retry-After header on a 429, or null when the
     * header is absent or non-numeric so the caller falls back to its default.
     */
    private function retryAfterSeconds(Response $response): ?int
    {
        $retryAfter = $response->header('Retry-After');

        if (! ctype_digit($retryAfter)) {
            return null;
        }

        return (int) $retryAfter;
    }

    /**
     * Remaining headroom for the shared per-client rate-limit buckets, which
     * are app-wide: every athlete sees the same numbers.
     *
     * @return array{'15min': int, 'daily': int}
     */
    public function rateLimitRemaining(): array
    {
        return [
            '15min' => $this->remainingBelow('15min', self::RATE_LIMIT_15MIN_MAX),
            'daily' => $this->remainingBelow('daily', self::RATE_LIMIT_DAILY_MAX),
        ];
    }

    /**
     * Reads a {@see StravaReadPriority::Background} caller may still spend
     * before it reaches the live-ingest reserve. Distinct from
     * {@see rateLimitRemaining()}, which reports the raw pool including the
     * reserve on purpose (the sync log and Pulse card want the true budget).
     *
     * @return array{'15min': int, 'daily': int}
     */
    public function backgroundHeadroom(): array
    {
        $ceilings = $this->backgroundCeilings();

        return [
            '15min' => $this->remainingBelow('15min', $ceilings['15min']),
            'daily' => $this->remainingBelow('daily', $ceilings['daily']),
        ];
    }

    private function remainingBelow(string $bucket, int $ceiling): int
    {
        return max(0, RateLimiter::remaining($this->rateLimitKey($bucket), $ceiling));
    }

    public function refreshIfExpired(StravaConnection $connection): StravaConnection
    {
        if ($this->tokenIsFresh($connection)) {
            return $connection;
        }

        // Serialize refreshes per connection: without the lock two concurrent
        // workers could both POST /oauth/token, and Strava's rotated
        // refresh_token from the first call invalidates the second.
        return Cache::lock("strava-refresh:{$connection->id}", self::REFRESH_LOCK_SECONDS)->block(
            self::REFRESH_LOCK_SECONDS,
            function () use ($connection): StravaConnection {
                // Re-read inside the lock: another worker may have just refreshed.
                $connection->refresh();
                if ($this->tokenIsFresh($connection)) {
                    return $connection;
                }

                return $this->performRefresh($connection);
            },
        );
    }

    private function tokenIsFresh(StravaConnection $connection): bool
    {
        return $connection->token_expires_at->isAfter(Carbon::now()->addSeconds(self::REFRESH_BUFFER_SECONDS));
    }

    private function performRefresh(StravaConnection $connection): StravaConnection
    {
        try {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'client_id' => config('services.strava.client_id'),
                'client_secret' => config('services.strava.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
            ]);
        } catch (ConnectionException $e) {
            // Transport failure / timeout reaching the token endpoint: Strava is
            // unreachable, not deauthorizing us. Treat it as transient so the
            // caller backs off instead of revoking a healthy connection.
            throw new StravaTokenRefreshTransientException(
                "Strava token refresh could not reach the endpoint: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->failed()) {
            if ($response->status() === 400) {
                // Only a 400 invalid_grant is a permanent deauthorization: the
                // refresh token will never succeed, so the caller revokes.
                throw new StravaTokenRefreshFailedException(
                    'Strava token refresh failed with status 400 (invalid_grant).',
                );
            }

            // 401 / 429 / 5xx are transient: the refresh may succeed on retry, so
            // the caller releases the job and backs off instead of revoking.
            throw new StravaTokenRefreshTransientException(
                "Strava token refresh failed transiently with status {$response->status()}.",
            );
        }

        $accessToken = $response->json('access_token');
        $refreshToken = $response->json('refresh_token');
        $expiresAt = $response->json('expires_at');

        if (! is_string($accessToken) || ! is_string($refreshToken) || ! is_int($expiresAt)) {
            throw new StravaTokenRefreshTransientException(
                'Strava token refresh returned an unexpected response shape: missing or invalid token fields.',
            );
        }

        $connection->update([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_expires_at' => new Carbon('@' . $expiresAt)->setTimezone(config('app.timezone')),
        ]);

        return $connection;
    }

    private function guardRateLimit(StravaReadPriority $priority): void
    {
        $backgroundCeilings = $this->backgroundCeilings();

        $buckets = [
            ['bucket' => '15min', 'max' => self::RATE_LIMIT_15MIN_MAX, 'decay' => self::RATE_LIMIT_15MIN_DECAY],
            ['bucket' => 'daily', 'max' => self::RATE_LIMIT_DAILY_MAX, 'decay' => self::RATE_LIMIT_DAILY_DECAY],
        ];

        foreach ($buckets as ['bucket' => $bucket, 'max' => $max]) {
            $key = $this->rateLimitKey($bucket);
            $ceiling = $priority->isLive() ? $max : $backgroundCeilings[$bucket];

            if (RateLimiter::tooManyAttempts($key, $ceiling)) {
                Pulse::record('strava_rate_limited', $key)->count();

                throw new StravaRateLimitedException(
                    $priority->isLive()
                        ? "Strava rate limit exhausted for bucket [{$key}]; retry in ".RateLimiter::availableIn($key).'s.'
                        : "Strava bucket [{$key}] is down to its live-ingest reserve; background read deferred, retry in ".RateLimiter::availableIn($key).'s.',
                );
            }
        }

        foreach ($buckets as ['bucket' => $bucket, 'decay' => $decay]) {
            RateLimiter::hit($this->rateLimitKey($bucket), $decay);
        }
    }

    /**
     * Highest attempt count a background read may push each bucket to. The
     * 15-minute bucket holds back LIVE_RESERVE_PERCENT, since a burst is what
     * gets the app throttled; the daily bucket holds back only a flat floor, so
     * background reads borrow whatever live ingest leaves unspent that day.
     *
     * @return array{'15min': int, daily: int}
     */
    private function backgroundCeilings(): array
    {
        $liveFloor = max(0, min(self::RATE_LIMIT_DAILY_MAX, (int) config('strava.live_read_floor')));

        return [
            '15min' => self::RATE_LIMIT_15MIN_MAX - intdiv(self::RATE_LIMIT_15MIN_MAX * self::LIVE_RESERVE_PERCENT, 100),
            'daily' => self::RATE_LIMIT_DAILY_MAX - $liveFloor,
        ];
    }

    /**
     * Keyed per API client, never per athlete: Strava meters the whole OAuth
     * application. Reintroducing a user id here would hand every athlete a
     * private allowance and blow the one shared limit.
     */
    private function rateLimitKey(string $bucket): string
    {
        return "strava-api:{$bucket}";
    }
}
