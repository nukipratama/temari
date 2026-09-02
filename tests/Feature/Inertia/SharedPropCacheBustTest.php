<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\StravaConnection;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Support\SharedPropCacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * A stale shared prop is a user-visible bug — "Send notification" stays greyed
 * out after you connect Telegram, or a revoked Strava link keeps reading as
 * live. One case per write path that can move one of the cached props, each
 * asserting the *next* request already sees the change rather than waiting out
 * the TTL.
 */

/**
 * One full page load, which warms every cached shared prop.
 *
 * Always signs in a freshly-read user. `actingAs()` keeps the exact instance it
 * is handed, so a relation lazy-loaded during one request would stay memoised on
 * that object for the next one — an artefact of the test harness, not of a real
 * request, which rebuilds the user from the session every time.
 */
function visitAs(User $user): TestResponse
{
    return test()->actingAs($user->fresh())->get('/profile');
}

function warmSharedProps(User $user): void
{
    visitAs($user)->assertSuccessful();
}

it('serves a cached prop without recomputing it on the next request', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    config(['services.telegram.bot_token' => 'test-token']);

    warmSharedProps($user);

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains((string) $query->sql, 'telegram_connections')) {
            $queries++;
        }
    });

    visitAs($user)
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('telegramConnected', true));

    expect($queries)->toBe(0);
});

it('reflects a Telegram connect on the very next request', function (): void {
    config(['services.telegram.bot_token' => 'test-token']);
    $user = User::factory()->create();

    warmSharedProps($user);

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('telegramConnected', false));

    TelegramConnection::query()->updateOrCreate(
        ['user_id' => $user->id],
        ['chat_id' => 4242, 'username' => 'nuki', 'revoked_at' => null],
    );

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('telegramConnected', true));
});

it('reflects a Telegram revoke on the very next request', function (): void {
    config(['services.telegram.bot_token' => 'test-token']);
    $user = User::factory()->create();
    $connection = TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);

    warmSharedProps($user);

    $connection->markRevoked();

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('telegramConnected', false));
});

it('reflects a Telegram mute saved from the settings page', function (): void {
    config(['services.telegram.bot_token' => 'test-token']);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);

    warmSharedProps($user);

    $user->notificationPreference()->updateOrCreate([], [
        'notifications_enabled' => true,
        'telegram_enabled' => false,
        'push_enabled' => true,
    ]);

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('telegramConnected', false));
});

it('reflects a push subscribe and unsubscribe on the very next request', function (): void {
    $user = User::factory()->create();

    warmSharedProps($user);

    $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';

    $this->actingAs($user)->postJson(route('push.subscribe'), [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 22)],
    ])->assertNoContent();

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('webPushSubscribed', true));

    $this->actingAs($user)
        ->deleteJson(route('push.unsubscribe'), ['endpoint' => $endpoint])
        ->assertNoContent();

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('webPushSubscribed', false));
});

it('reflects a push mute saved from the settings page', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['push_enabled' => true]);
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/zzz', str_repeat('a', 87), str_repeat('b', 22));
    SharedPropCacheKey::WebPushSubscribed->forget($user->id);

    warmSharedProps($user);

    $user->notificationPreference->update(['push_enabled' => false]);

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('webPushSubscribed', false));
});

it('reflects a Strava reconnect that grants the missing zone scope', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all']);

    warmSharedProps($user);

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('stravaZoneScopeMissing', true));

    $connection->update(['scopes' => 'read,activity:read_all,profile:read_all']);

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('stravaZoneScopeMissing', false));
});

it('reflects a Strava revoke on the very next request', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all']);

    warmSharedProps($user);

    $connection->markRevoked();

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('stravaZoneScopeMissing', false));
});

it('reflects a Strava revoke in stravaSync on the very next request', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create();

    warmSharedProps($user);

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('stravaSync.state', 'syncing'));

    $connection->markRevoked();

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('stravaSync.state', 'revoked'));
});

it('reflects a first Strava connect in stravaSync on the very next request', function (): void {
    $user = User::factory()->create();

    warmSharedProps($user);

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('stravaSync.state', 'disconnected'));

    StravaConnection::factory()->for($user)->create();

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('stravaSync.state', 'syncing'));
});

it('busts only the acting user cache, never a bystander', function (): void {
    config(['services.telegram.bot_token' => 'test-token']);
    $user = User::factory()->create();
    $other = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    TelegramConnection::factory()->for($other)->create(['revoked_at' => null]);

    warmSharedProps($other);
    warmSharedProps($user);

    $this->actingAs($user)->delete('/profile/telegram')->assertRedirect();

    expect(Cache::has(SharedPropCacheKey::TelegramConnected->key($other->id)))->toBeTrue()
        ->and(Cache::has(SharedPropCacheKey::TelegramConnected->key($user->id)))->toBeFalse();
});
