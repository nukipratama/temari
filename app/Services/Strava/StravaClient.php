<?php

declare(strict_types=1);

namespace App\Services\Strava;

use App\Enums\StravaReadPriority;
use App\Enums\StravaReadSource;
use App\Enums\StravaGrantReleaseStatus;
use App\Models\Analytics\StravaRead;
use App\Models\StravaConnection;
use App\Models\StravaGrantToken;
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
use Throwable;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pulse\Facades\Pulse;

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
    public const int RATE_LIMIT_15MIN_MAX = 200;

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
        StravaReadSource $source,
        StravaReadPriority $priority = StravaReadPriority::Live,
        array $query = [],
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

        $this->recordRead($response, $source, $priority, $path);

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

    private function recordRead(Response $response, StravaReadSource $source, StravaReadPriority $priority, string $path): void
    {
        [$usage15m, $usageDaily] = $this->readRateLimitUsage($response);

        try {
            StravaRead::query()->create([
                'read_at' => now(),
                'source' => $source,
                'priority' => $priority,
                'endpoint' => $this->endpointCategory($path),
                'http_status' => $response->status(),
                'usage_15m' => $usage15m,
                'usage_daily' => $usageDaily,
            ]);
        } catch (Throwable $e) {
            Log::warning('strava read telemetry could not be stored', [
                'source' => $source->value,
                'priority' => $priority->value,
                'endpoint' => $this->endpointCategory($path),
                'status' => $response->status(),
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{?int, ?int}
     */
    private function readRateLimitUsage(Response $response): array
    {
        $header = $response->header('X-ReadRateLimit-Usage');
        if (preg_match('/^\s*(\d+)\s*,\s*(\d+)\s*$/D', $header, $matches) !== 1) {
            return [null, null];
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    private function endpointCategory(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return match (true) {
            $path === '/athlete/activities' => 'activity_list',
            $path === '/athlete/zones' => 'zones',
            $path === '/athlete' => 'athlete',
            preg_match('~^/activities/\d+/streams$~', $path) === 1 => 'activity_streams',
            preg_match('~^/activities/\d+$~', $path) === 1 => 'activity_detail',
            default => 'other',
        };
    }

    public function deauthorizeGrantToken(StravaGrantToken $grant): StravaGrantReleaseResult
    {
        try {
            $tokens = $this->requestRefreshedTokens($grant->refresh_token, release: true);
        } catch (StravaTokenRefreshFailedException $e) {
            return new StravaGrantReleaseResult(StravaGrantReleaseStatus::Rejected, $e->getMessage());
        } catch (StravaTokenRefreshTransientException $e) {
            return new StravaGrantReleaseResult(StravaGrantReleaseStatus::Failed, $e->getMessage());
        }

        if (! app(StravaGrantLedger::class)->persistGrantRefresh(
            $grant->strava_athlete_id,
            $grant->credential_version,
            $tokens['refresh_token'],
        )) {
            return new StravaGrantReleaseResult(StravaGrantReleaseStatus::Stale);
        }

        try {
            $response = Http::asForm()->post(self::DEAUTHORIZE_URL, [
                'access_token' => $tokens['access_token'],
            ]);
        } catch (ConnectionException $e) {
            return new StravaGrantReleaseResult(StravaGrantReleaseStatus::Failed, $e->getMessage());
        }

        if ($response->status() === 401 || ($response->failed() && $response->json('error') === 'invalid_grant')) {
            return new StravaGrantReleaseResult(
                StravaGrantReleaseStatus::Rejected,
                "Strava reported the grant as unavailable (HTTP {$response->status()}).",
            );
        }

        if (! $response->successful()) {
            return new StravaGrantReleaseResult(
                StravaGrantReleaseStatus::Failed,
                "Strava returned HTTP {$response->status()}.",
            );
        }

        return new StravaGrantReleaseResult(StravaGrantReleaseStatus::Released);
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

                return $this->performRefresh($connection, $connection->credential_version);
            },
        );
    }

    private function tokenIsFresh(StravaConnection $connection): bool
    {
        return $connection->token_expires_at->isAfter(Carbon::now()->addSeconds(self::REFRESH_BUFFER_SECONDS));
    }

    private function performRefresh(StravaConnection $connection, int $credentialVersion): StravaConnection
    {
        $tokens = $this->requestRefreshedTokens($connection->refresh_token);

        app(StravaGrantLedger::class)->persistConnectionRefresh(
            $connection,
            $credentialVersion,
            $tokens['access_token'],
            $tokens['refresh_token'],
            $tokens['expires_at'],
        );

        return $connection->fresh() ?? $connection;
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: Carbon}
     */
    private function requestRefreshedTokens(string $refreshToken, bool $release = false): array
    {
        try {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'client_id' => config('services.strava.client_id'),
                'client_secret' => config('services.strava.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
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
            $rejected = $response->status() === 400
                || ($release && $response->status() === 401);

            if ($rejected) {
                throw new StravaTokenRefreshFailedException(
                    "Strava token refresh rejected the grant with status {$response->status()}.",
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

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => new Carbon('@' . $expiresAt)->setTimezone(config('app.timezone')),
        ];
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
        return [
            '15min' => self::RATE_LIMIT_15MIN_MAX - intdiv(self::RATE_LIMIT_15MIN_MAX * self::LIVE_RESERVE_PERCENT, 100),
            'daily' => self::RATE_LIMIT_DAILY_MAX - (int) config('strava.live_read_floor'),
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
