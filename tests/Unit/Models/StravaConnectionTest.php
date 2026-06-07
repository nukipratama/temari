<?php

declare(strict_types=1);

use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

it('encrypts the access token at rest', function (): void {
    $connection = StravaConnection::factory()->create(['access_token' => 'plain-access']);

    $stored = $connection->getRawOriginal('access_token');

    expect($stored)->not->toBe('plain-access')
        ->and(Crypt::decryptString($stored))->toBe('plain-access')
        ->and($connection->access_token)->toBe('plain-access');
});

it('encrypts the refresh token at rest', function (): void {
    $connection = StravaConnection::factory()->create(['refresh_token' => 'plain-refresh']);

    $stored = $connection->getRawOriginal('refresh_token');

    expect($stored)->not->toBe('plain-refresh')
        ->and(Crypt::decryptString($stored))->toBe('plain-refresh')
        ->and($connection->refresh_token)->toBe('plain-refresh');
});

it('casts token_expires_at to a Carbon instance', function (): void {
    $connection = StravaConnection::factory()->create(['token_expires_at' => '2026-01-01 00:00:00']);

    expect($connection->token_expires_at)->toBeInstanceOf(Carbon::class)
        ->and($connection->token_expires_at->toDateTimeString())->toBe('2026-01-01 00:00:00');
});

it('casts strava_athlete_id to an integer on read', function (): void {
    $connection = new StravaConnection();
    $connection->strava_athlete_id = '987654';

    expect($connection->strava_athlete_id)->toBe(987654);
});

it('hides sensitive tokens from array serialization', function (): void {
    $connection = StravaConnection::factory()->create();

    $array = $connection->toArray();

    expect($array)->not->toHaveKey('access_token')
        ->and($array)->not->toHaveKey('refresh_token');
});

it('enforces unique strava_athlete_id', function (): void {
    StravaConnection::factory()->create(['strava_athlete_id' => 12345]);

    expect(fn () => StravaConnection::factory()->create(['strava_athlete_id' => 12345]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('enforces one connection per user', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->create(['user_id' => $user->id]);

    expect(fn () => StravaConnection::factory()->create(['user_id' => $user->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->create(['user_id' => $user->id]);

    expect($connection->user)->toBeInstanceOf(User::class)
        ->and($connection->user->is($user))->toBeTrue();
});

it('casts revoked_at to a Carbon instance', function (): void {
    $connection = StravaConnection::factory()->create(['revoked_at' => '2026-01-01 00:00:00']);

    expect($connection->revoked_at)->toBeInstanceOf(Carbon::class)
        ->and($connection->revoked_at->toDateTimeString())->toBe('2026-01-01 00:00:00');
});

it('reports revoked state via isRevoked', function (): void {
    $active = StravaConnection::factory()->create();
    $revoked = StravaConnection::factory()->create(['revoked_at' => Carbon::now()]);

    expect($active->isRevoked())->toBeFalse()
        ->and($revoked->isRevoked())->toBeTrue();
});

it('stamps revoked_at via markRevoked and is a no-op when already revoked', function (): void {
    $connection = StravaConnection::factory()->create();

    $connection->markRevoked();
    expect($connection->fresh()->isRevoked())->toBeTrue();

    $stampedAt = $connection->fresh()->revoked_at;
    $connection->markRevoked();
    expect($connection->fresh()->revoked_at->equalTo($stampedAt))->toBeTrue();
});

it('excludes revoked connections from the active scope', function (): void {
    $active = StravaConnection::factory()->create();
    StravaConnection::factory()->create(['revoked_at' => Carbon::now()]);

    $ids = StravaConnection::query()->active()->pluck('id')->all();

    expect($ids)->toBe([$active->id]);
});
