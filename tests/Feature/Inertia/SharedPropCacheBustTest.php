<?php

declare(strict_types=1);

use App\Models\InboxNotification;
use App\Models\StravaConnection;
use App\Models\User;
use App\Support\SharedPropCacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * A stale shared prop is a user-visible bug — a read notification keeps its
 * unread badge, or a revoked Strava link keeps reading as live. One case per
 * write path that can move one of the cached props, each asserting the *next*
 * request already sees the change rather than waiting out the TTL.
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
    InboxNotification::factory()->for($user)->create();

    warmSharedProps($user);

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains((string) $query->sql, 'inbox_notifications')) {
            $queries++;
        }
    });

    visitAs($user)
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('unreadNotifications', 1));

    expect($queries)->toBe(0);
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

it('reflects a Strava revoke in stravaZoneScopeMissing and stravaSync on the very next request', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all']);

    warmSharedProps($user);

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page->where('stravaSync.state', 'syncing'));

    $connection->markRevoked();

    visitAs($user)
        ->assertInertia(fn (Assert $page) => $page
            ->where('stravaZoneScopeMissing', false)
            ->where('stravaSync.state', 'revoked'));
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
    $user = User::factory()->create();
    $other = User::factory()->create();
    $notification = InboxNotification::factory()->for($user)->create();
    InboxNotification::factory()->for($other)->create();

    warmSharedProps($other);
    warmSharedProps($user);

    $notification->markRead();

    expect(Cache::has(SharedPropCacheKey::UnreadNotifications->key($other->id)))->toBeTrue()
        ->and(Cache::has(SharedPropCacheKey::UnreadNotifications->key($user->id)))->toBeFalse();
});
