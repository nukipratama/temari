<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\User;
use App\Notifications\StravaDisconnectedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('encrypts the access token at rest', function (): void {
    $connection = StravaConnection::factory()->make(['user_id' => 1, 'access_token' => 'plain-access']);

    // getRawOriginal() needs a synced $original snapshot (only set on save()); an
    // unpersisted model's raw stored value lives in getAttributes() instead, and
    // the encrypted cast is applied at set-time regardless of persistence.
    $stored = $connection->getAttributes()['access_token'];

    expect($stored)->not->toBe('plain-access')
        ->and(Crypt::decryptString($stored))->toBe('plain-access')
        ->and($connection->access_token)->toBe('plain-access');
});

it('encrypts the refresh token at rest', function (): void {
    $connection = StravaConnection::factory()->make(['user_id' => 1, 'refresh_token' => 'plain-refresh']);

    $stored = $connection->getAttributes()['refresh_token'];

    expect($stored)->not->toBe('plain-refresh')
        ->and(Crypt::decryptString($stored))->toBe('plain-refresh')
        ->and($connection->refresh_token)->toBe('plain-refresh');
});

it('casts token_expires_at to a Carbon instance', function (): void {
    $connection = StravaConnection::factory()->make(['user_id' => 1, 'token_expires_at' => '2026-01-01 00:00:00']);

    expect($connection->token_expires_at)->toBeInstanceOf(Carbon::class)
        ->and($connection->token_expires_at->toDateTimeString())->toBe('2026-01-01 00:00:00');
});

it('casts strava_athlete_id to an integer on read', function (): void {
    $connection = new StravaConnection();
    $connection->strava_athlete_id = '987654';

    expect($connection->strava_athlete_id)->toBe(987654);
});

it('hides sensitive tokens from array serialization', function (): void {
    $connection = StravaConnection::factory()->make(['user_id' => 1]);

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
    $connection = StravaConnection::factory()->make(['user_id' => 1, 'revoked_at' => '2026-01-01 00:00:00']);

    expect($connection->revoked_at)->toBeInstanceOf(Carbon::class)
        ->and($connection->revoked_at->toDateTimeString())->toBe('2026-01-01 00:00:00');
});

it('reports revoked state via isRevoked', function (): void {
    $active = StravaConnection::factory()->make(['user_id' => 1]);
    $revoked = StravaConnection::factory()->make(['user_id' => 1, 'revoked_at' => Carbon::now()]);

    expect($active->isRevoked())->toBeFalse()
        ->and($revoked->isRevoked())->toBeTrue();
});

it('reports the zone scope via hasZoneScope', function (): void {
    $scoped = StravaConnection::factory()->make(['user_id' => 1, 'scopes' => 'read,activity:read_all,profile:read_all']);
    $unscoped = StravaConnection::factory()->make(['user_id' => 1, 'scopes' => 'read,activity:read_all']);

    expect($scoped->hasZoneScope())->toBeTrue()
        ->and($unscoped->hasZoneScope())->toBeFalse();
});

it('stamps revoked_at via markRevoked and is a no-op when already revoked', function (): void {
    $connection = StravaConnection::factory()->create();

    $connection->markRevoked();
    expect($connection->fresh()->isRevoked())->toBeTrue();

    $stampedAt = $connection->fresh()->revoked_at;
    $connection->markRevoked();
    expect($connection->fresh()->revoked_at->equalTo($stampedAt))->toBeTrue();
});

// One notification per revocation, from the one method every revoking call site
// already goes through — eight of them, and a per-call-site notify would drift the
// way "where can this user be reached" once did.
it('tells the athlete once that syncing stopped', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create();

    $connection->markRevoked();
    $connection->markRevoked();

    Notification::assertSentToTimes($user, StravaDisconnectedNotification::class, 1);
});

// Two failing jobs can each be holding an active copy of the row, which the
// in-memory check cannot see: only the update that flips revoked_at notifies.
it('tells the athlete once even when two loaded copies race to revoke', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create();
    $racer = StravaConnection::query()->findOrFail($connection->id);

    $connection->markRevoked();
    $racer->markRevoked();

    Notification::assertSentToTimes($user, StravaDisconnectedNotification::class, 1);
});

it('carries the revocation instant into the notification, so a re-revocation is its own row', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create();

    $connection->markRevoked();

    Notification::assertSentTo(
        $user,
        StravaDisconnectedNotification::class,
        fn (StravaDisconnectedNotification $notification): bool => $notification->revokedAt->toDateTimeString() === $connection->fresh()->revoked_at?->toDateTimeString(),
    );
});

// A deleted account is not an athlete to warn: the cascade takes the inbox row
// with it, and the queued send would arrive after the user is gone.
it('stays silent when the revocation is an account deletion', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $user->delete();

    Notification::assertNothingSent();
});

it('excludes revoked connections from the active scope', function (): void {
    $active = StravaConnection::factory()->create();
    StravaConnection::factory()->create(['revoked_at' => Carbon::now()]);

    $ids = StravaConnection::query()->active()->pluck('id')->all();

    expect($ids)->toBe([$active->id]);
});

it('purges the user un-ingested stubs on revoke, leaving analyzed runs and other users intact', function (): void {
    $userA = User::factory()->create();
    $conn = StravaConnection::factory()->for($userA)->create();
    Activity::factory()->stub()->for($userA)->count(3)->create(); // un-ingested stubs
    Activity::factory()->for($userA)->create();                   // analyzed run (default state)

    $userB = User::factory()->create();
    Activity::factory()->stub()->for($userB)->create();           // unrelated user's stub

    $conn->markRevoked();

    expect(Activity::withStubs()->where('user_id', $userA->id)->whereNull('analyzed_at')->count())->toBe(0)
        ->and(Activity::withStubs()->where('user_id', $userA->id)->whereNotNull('analyzed_at')->count())->toBe(1)
        ->and(Activity::withStubs()->where('user_id', $userB->id)->whereNull('analyzed_at')->count())->toBe(1);
});

it('does not purge stubs when markRevoked is a no-op (already revoked)', function (): void {
    $user = User::factory()->create();
    $conn = StravaConnection::factory()->for($user)->create(['revoked_at' => Carbon::now()]);
    Activity::factory()->stub()->for($user)->create();

    $conn->markRevoked(); // already revoked → early return, no purge

    expect(Activity::withStubs()->where('user_id', $user->id)->whereNull('analyzed_at')->count())->toBe(1);
});
