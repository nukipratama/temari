<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pushPayload(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): array
{
    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-token']];
}

it('requires authentication to subscribe', function (): void {
    $this->postJson('/profile/push', pushPayload())->assertUnauthorized();
});

it('stores a push subscription tied to the authenticated user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/profile/push', pushPayload())->assertNoContent();

    $this->assertDatabaseHas('push_subscriptions', [
        'subscribable_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
    ]);
});

it('rejects a subscription pointed at an internal SSRF host', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/profile/push', pushPayload('https://169.254.169.254/x'))->assertStatus(422);

    $this->assertDatabaseCount('push_subscriptions', 0);
});

it('rejects a delete request missing the endpoint', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->deleteJson('/profile/push', [])->assertStatus(422);
});

it("deletes the user's own push subscription", function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'k', 't');

    $this->actingAs($user)
        ->deleteJson('/profile/push', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])
        ->assertNoContent();

    $this->assertDatabaseCount('push_subscriptions', 0);
});

it('blocks the shared demo account from subscribing', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);

    $this->actingAs($demo)->postJson('/profile/push', pushPayload())->assertForbidden();

    $this->assertDatabaseCount('push_subscriptions', 0);
});

it('replaces the subscription this device saved before when it re-subscribes', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://web.push.apple.com/old-iphone', 'k', 't');

    $this->actingAs($user)
        ->postJson('/profile/push', [...pushPayload('https://web.push.apple.com/new-iphone'), 'previous_endpoint' => 'https://web.push.apple.com/old-iphone'])
        ->assertNoContent();

    expect($user->pushSubscriptions()->pluck('endpoint')->all())->toBe(['https://web.push.apple.com/new-iphone']);
});

it("keeps the user's other devices on the same push service", function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://web.push.apple.com/ipad', 'k', 't');
    $user->updatePushSubscription('https://web.push.apple.com/old-iphone', 'k', 't');

    $this->actingAs($user)
        ->postJson('/profile/push', [...pushPayload('https://web.push.apple.com/new-iphone'), 'previous_endpoint' => 'https://web.push.apple.com/old-iphone'])
        ->assertNoContent();

    expect($user->pushSubscriptions()->orderBy('endpoint')->pluck('endpoint')->all())
        ->toBe(['https://web.push.apple.com/ipad', 'https://web.push.apple.com/new-iphone']);
});

it('keeps every existing subscription when no previous endpoint is sent', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://web.push.apple.com/ipad', 'k', 't');

    $this->actingAs($user)->postJson('/profile/push', pushPayload('https://web.push.apple.com/new-iphone'))->assertNoContent();

    expect($user->pushSubscriptions()->count())->toBe(2);
});

it('keeps the subscription when the previous endpoint is the one being saved', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/profile/push', [...pushPayload('https://web.push.apple.com/same'), 'previous_endpoint' => 'https://web.push.apple.com/same'])
        ->assertNoContent();

    expect($user->pushSubscriptions()->pluck('endpoint')->all())->toBe(['https://web.push.apple.com/same']);
});

it("never deletes another user's subscription named as the previous endpoint", function (): void {
    $other = User::factory()->create();
    $other->updatePushSubscription('https://web.push.apple.com/someone-else', 'k', 't');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/profile/push', [...pushPayload('https://web.push.apple.com/mine'), 'previous_endpoint' => 'https://web.push.apple.com/someone-else'])
        ->assertNoContent();

    expect($other->pushSubscriptions()->pluck('endpoint')->all())->toBe(['https://web.push.apple.com/someone-else']);
});

it('rejects an over-long previous endpoint', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/profile/push', [...pushPayload(), 'previous_endpoint' => 'https://web.push.apple.com/'.str_repeat('a', 500)])
        ->assertStatus(422);
});
