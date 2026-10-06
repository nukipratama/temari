<?php

declare(strict_types=1);

namespace App\Services\Strava;

use App\Enums\StravaGrantReleaseStatus;
use App\Enums\StravaReadPriority;
use App\Enums\StravaReadSource;
use App\Models\Analytics\StravaRead;
use App\Models\StravaConnection;
use App\Models\StravaGrantToken;
use App\Services\Strava\Exceptions\StravaCircuitOpenException;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshTransientException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
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

    public const int REFRESH_LOCK_TTL_SECONDS = 90;

    public const int REFRESH_LOCK_WAIT_SECONDS = 5;

    public const int HTTP_CONNECT_TIMEOUT_SECONDS = 5;

    public const int HTTP_TIMEOUT_SECONDS = 15;

    // Strava enforces rate limits per CLIENT (the whole app), not per athlete, so
    // these buckets are keyed globally and shared across every connected user. The
    // values are this app's own Read allocation per its Strava API dashboard
    // (200 / 15min, 2000 / day), not the lower 100 / 1000 default the public
    // docs quote; they bind before the Overall limits (400 / 4000) because all
    // of our calls are reads.
    public const int RATE_LIMIT_15MIN_MAX = 200;

    private const int RATE_LIMIT_DAILY_MAX = 2000;

    // Share of the 15-minute bucket only StravaReadPriority::Live may spend. A
    // freshly-finished run appearing promptly is the product's core promise; a
    // user scrolling their 2019 archive can wait for the bucket to roll over.
    // The daily bucket reserves a flat floor instead (`strava.live_read_floor`).
    private const int LIVE_RESERVE_PERCENT = 25;

    public function __construct(private readonly ?StravaCircuitBreaker $breaker = null)
    {
    }

    public static function refreshLockKey(int $stravaAthleteId): string
    {
        return "strava-refresh:{$stravaAthleteId}";
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

        $sentAt = CarbonImmutable::now();
        $this->guardRateLimit($priority, $sentAt);

        try {
            $response = $this->http()->baseUrl(self::apiBaseUrl())
                ->withToken($connection->access_token)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            // Transport failure / timeout: Strava is unreachable — count it.
            $breaker->recordFailure();

            throw $e;
        }

        $this->recordRead($response, $source, $priority, $path, $sentAt);

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

    private function recordRead(Response $response, StravaReadSource $source, StravaReadPriority $priority, string $path, CarbonImmutable $sentAt): void
    {
        [$usage15m, $usageDaily] = $this->readRateLimitUsage($response);

        $this->rememberReportedUsage('15min', $usage15m, $sentAt);
        $this->rememberReportedUsage('daily', $usageDaily, $sentAt);

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
            $response = $this->http()->asForm()->post(self::DEAUTHORIZE_URL, [
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

    private function http(): PendingRequest
    {
        return Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)->timeout(self::HTTP_TIMEOUT_SECONDS);
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
        return max(0, $ceiling - $this->usage($bucket, CarbonImmutable::now()));
    }

    /**
     * Reads counted against the bucket's current Strava window: the local
     * count, raised to the usage Strava last reported for that same window.
     */
    private function usage(string $bucket, CarbonImmutable $at): int
    {
        $reported = $this->limiterStore()->get(self::reportedUsageKey($bucket, $at));

        return max(RateLimiter::attempts(self::rateLimitKey($bucket, $at)), is_numeric($reported) ? (int) $reported : 0);
    }

    private function rememberReportedUsage(string $bucket, ?int $usage, CarbonImmutable $sentAt): void
    {
        if ($usage === null || $usage <= $this->usage($bucket, $sentAt)) {
            return;
        }

        $this->limiterStore()->put(self::reportedUsageKey($bucket, $sentAt), $usage, self::windowEnd($bucket, $sentAt));
    }

    private function limiterStore(): Repository
    {
        return Cache::store(config('cache.limiter'));
    }

    public function refreshIfExpired(StravaConnection $connection): StravaConnection
    {
        if ($this->tokenIsFresh($connection)) {
            return $connection;
        }

        // Refreshes and grant releases share this athlete-level lock because each refresh rotates the token.
        return Cache::lock(self::refreshLockKey($connection->strava_athlete_id), self::REFRESH_LOCK_TTL_SECONDS)->block(
            self::REFRESH_LOCK_WAIT_SECONDS,
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
            $response = $this->http()->asForm()->post(self::TOKEN_URL, [
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
            // A 400 about the Application (client id/secret) is our misconfiguration, not a dead grant.
            $refreshTokenRejected = $response->status() === 400
                && collect((array) $response->json('errors'))->contains('resource', 'RefreshToken');
            $rejected = $refreshTokenRejected
                || ($release && $response->status() === 401);

            if ($rejected) {
                throw new StravaTokenRefreshFailedException(
                    "Strava token refresh rejected the grant with status {$response->status()}.",
                );
            }

            // 401 / 429 / 5xx are transient: the refresh may succeed on retry, so
            // the caller releases the job and backs off instead of revoking.
            throw new StravaTokenRefreshTransientException(
                "Strava token refresh failed transiently with status {$response->status()}{$this->errorSubject($response)}.",
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

    private function errorSubject(Response $response): string
    {
        $resource = $response->json('errors.0.resource');
        $field = $response->json('errors.0.field');

        return is_string($resource) && is_string($field) ? " ({$resource} {$field})" : '';
    }

    private function guardRateLimit(StravaReadPriority $priority, CarbonImmutable $at): void
    {
        $backgroundCeilings = $this->backgroundCeilings();

        $buckets = [
            '15min' => self::RATE_LIMIT_15MIN_MAX,
            'daily' => self::RATE_LIMIT_DAILY_MAX,
        ];

        foreach ($buckets as $bucket => $max) {
            $key = self::rateLimitKey($bucket, $at);
            $ceiling = $priority->isLive() ? $max : $backgroundCeilings[$bucket];

            if ($this->usage($bucket, $at) >= $ceiling) {
                Pulse::record('strava_rate_limited', $key)->count();

                $retryIn = self::secondsLeftInWindow($bucket, $at);

                throw new StravaRateLimitedException(
                    $priority->isLive()
                        ? "Strava rate limit exhausted for bucket [{$key}]; retry in {$retryIn}s."
                        : "Strava bucket [{$key}] is down to its live-ingest reserve; background read deferred, retry in {$retryIn}s.",
                );
            }
        }

        foreach (array_keys($buckets) as $bucket) {
            RateLimiter::hit(self::rateLimitKey($bucket, $at), self::secondsLeftInWindow($bucket, $at));
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
     * private allowance and blow the one shared limit. The window suffix follows
     * Strava's own resets: the UTC quarter-hour and the UTC date.
     */
    public static function rateLimitKey(string $bucket, ?CarbonInterface $at = null): string
    {
        $start = self::windowStart($bucket, CarbonImmutable::instance($at ?? Carbon::now()));

        return "strava-api:{$bucket}:".($bucket === 'daily' ? $start->toDateString() : $start->format('Y-m-d\TH:i'));
    }

    private static function reportedUsageKey(string $bucket, CarbonImmutable $at): string
    {
        return self::rateLimitKey($bucket, $at).':reported';
    }

    private static function windowStart(string $bucket, CarbonImmutable $at): CarbonImmutable
    {
        $utc = $at->utc();

        return $bucket === 'daily'
            ? $utc->startOfDay()
            : $utc->setTime($utc->hour, intdiv($utc->minute, 15) * 15);
    }

    private static function windowEnd(string $bucket, CarbonImmutable $at): CarbonImmutable
    {
        $start = self::windowStart($bucket, $at);

        return $bucket === 'daily' ? $start->addDay() : $start->addMinutes(15);
    }

    private static function secondsLeftInWindow(string $bucket, CarbonImmutable $at): int
    {
        return max(1, self::windowEnd($bucket, $at)->getTimestamp() - $at->getTimestamp());
    }
}
