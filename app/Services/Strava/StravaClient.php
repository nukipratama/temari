<?php

declare(strict_types=1);

namespace App\Services\Strava;

use App\Models\StravaConnection;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class StravaClient
{
    private const string API_BASE_URL = 'https://www.strava.com/api/v3';

    private const string TOKEN_URL = 'https://www.strava.com/oauth/token';

    /**
     * Refresh tokens with this many seconds left to avoid mid-request expiry.
     */
    private const int REFRESH_BUFFER_SECONDS = 60;

    /**
     * Strava's per-app shared rate limits. Buckets are app-wide, not per-user.
     *
     * @var array<string, array{int, int}>
     */
    private const array RATE_LIMITS = [
        'strava-api:15min' => [200, 15 * 60],
        'strava-api:daily' => [2000, 24 * 60 * 60],
    ];

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(StravaConnection $connection, string $path, array $query = []): Response
    {
        $connection = $this->refreshIfExpired($connection);

        $this->guardRateLimit();

        return Http::baseUrl(self::API_BASE_URL)
            ->withToken($connection->access_token)
            ->get($path, $query)
            ->throw();
    }

    public function refreshIfExpired(StravaConnection $connection): StravaConnection
    {
        if ($connection->token_expires_at->isAfter(Carbon::now()->addSeconds(self::REFRESH_BUFFER_SECONDS))) {
            return $connection;
        }

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

    private function guardRateLimit(): void
    {
        foreach (self::RATE_LIMITS as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new StravaRateLimitedException(
                    "Strava rate limit exhausted for bucket [{$key}]; retry in ".RateLimiter::availableIn($key).'s.',
                );
            }
        }

        foreach (self::RATE_LIMITS as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }
}
