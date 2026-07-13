<?php

declare(strict_types=1);

use App\Jobs\Strava\VerifyStravaRevocationJob;
use App\Models\Analytics\StravaSyncLog;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Strava\StravaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function runVerifyJob(StravaConnection $connection, string $source = 'webhook_deauth'): void
{
    (new VerifyStravaRevocationJob($connection->id, $source))->handle(app(StravaClient::class));
}

function freshConnection(): StravaConnection
{
    return StravaConnection::factory()->for(User::factory())->create([
        'access_token' => 'tok',
        'token_expires_at' => Carbon::now()->addHours(2),
    ]);
}

it('revokes the connection when Strava rejects the token with a 401 (genuine deauth)', function (): void {
    $connection = freshConnection();
    Http::fake(['strava.com/api/v3/athlete' => Http::response(['error' => 'Authorization Error'], 401)]);

    runVerifyJob($connection);

    expect($connection->fresh()->isRevoked())->toBeTrue()
        ->and(StravaSyncLog::query()->where('user_id', $connection->user_id)->where('status', 'revoked')->exists())->toBeTrue();
});

it('revokes when the token refresh is permanently rejected (invalid_grant)', function (): void {
    $connection = freshConnection();
    $connection->update(['token_expires_at' => Carbon::now()->subMinute()]);
    Http::fake(['strava.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    runVerifyJob($connection);

    expect($connection->fresh()->isRevoked())->toBeTrue();
});

it('does NOT revoke when the grant is still live (forged deauth event)', function (): void {
    $connection = freshConnection();
    Http::fake(['strava.com/api/v3/athlete' => Http::response(['id' => 42], 200)]);

    runVerifyJob($connection);

    expect($connection->fresh()->isRevoked())->toBeFalse()
        ->and(StravaSyncLog::query()->where('user_id', $connection->user_id)->exists())->toBeFalse();
});

it('no-ops when the connection is missing', function (): void {
    Http::fake();

    (new VerifyStravaRevocationJob(999_999, 'webhook_deauth'))->handle(app(StravaClient::class));

    Http::assertNothingSent();
});

it('no-ops when the connection is already revoked', function (): void {
    $connection = StravaConnection::factory()->for(User::factory())->revoked()->create();
    Http::fake();

    runVerifyJob($connection);

    Http::assertNothingSent();
});
