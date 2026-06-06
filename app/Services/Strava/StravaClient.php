<?php

declare(strict_types=1);

namespace App\Services\Strava;

use App\Models\StravaConnection;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pulse\Facades\Pulse;

class StravaClient
{
    private const string API_BASE_URL = 'https://www.strava.com/api/v3';

    private const string TOKEN_URL = 'https://www.strava.com/oauth/token';

    private const int REFRESH_BUFFER_SECONDS = 60;

    private const int REFRESH_LOCK_SECONDS = 15;

    private const int RATE_LIMIT_15MIN_MAX = 200;

    private const int RATE_LIMIT_15MIN_DECAY = 15 * 60;

    private const int RATE_LIMIT_DAILY_MAX = 2000;

    private const int RATE_LIMIT_DAILY_DECAY = 24 * 60 * 60;

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(StravaConnection $connection, string $path, array $query = []): Response
    {
        $connection = $this->refreshIfExpired($connection);

        $this->guardRateLimit($connection->user_id);

        return Http::baseUrl(self::API_BASE_URL)
            ->withToken($connection->access_token)
            ->get($path, $query)
            ->throw();
    }

    /**
     * Remaining headroom for a user's rate-limit buckets.
     *
     * @return array{'15min': int, 'daily': int}
     */
    public function rateLimitRemaining(int $userId): array
    {
        return [
            '15min' => max(0, RateLimiter::remaining($this->rateLimitKey($userId, '15min'), self::RATE_LIMIT_15MIN_MAX)),
            'daily' => max(0, RateLimiter::remaining($this->rateLimitKey($userId, 'daily'), self::RATE_LIMIT_DAILY_MAX)),
        ];
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
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.strava.client_id'),
            'client_secret' => config('services.strava.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
        ]);

        if ($response->failed()) {
            throw new StravaTokenRefreshFailedException(
                "Strava token refresh failed with status {$response->status()}: {$response->body()}",
            );
        }

        $connection->update([
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'token_expires_at' => Carbon::createFromTimestamp($response->json('expires_at')),
        ]);

        return $connection;
    }

    private function guardRateLimit(int $userId): void
    {
        $buckets = [
            ['key' => $this->rateLimitKey($userId, '15min'), 'max' => self::RATE_LIMIT_15MIN_MAX, 'decay' => self::RATE_LIMIT_15MIN_DECAY],
            ['key' => $this->rateLimitKey($userId, 'daily'), 'max' => self::RATE_LIMIT_DAILY_MAX, 'decay' => self::RATE_LIMIT_DAILY_DECAY],
        ];

        foreach ($buckets as ['key' => $key, 'max' => $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                Pulse::record('strava_rate_limited', $key)->count();

                throw new StravaRateLimitedException(
                    "Strava rate limit exhausted for bucket [{$key}]; retry in ".RateLimiter::availableIn($key).'s.',
                );
            }
        }

        foreach ($buckets as ['key' => $key, 'decay' => $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    private function rateLimitKey(int $userId, string $bucket): string
    {
        return "strava-api:{$userId}:{$bucket}";
    }
}
