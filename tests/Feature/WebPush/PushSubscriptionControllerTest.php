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
    $this->postJson('/profil/push', pushPayload())->assertUnauthorized();
});

it('stores a push subscription tied to the authenticated user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/profil/push', pushPayload())->assertNoContent();

    $this->assertDatabaseHas('push_subscriptions', [
        'subscribable_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
    ]);
});

it('rejects a subscription pointed at an internal SSRF host', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/profil/push', pushPayload('https://169.254.169.254/x'))->assertStatus(422);

    $this->assertDatabaseCount('push_subscriptions', 0);
});

it('rejects a delete request missing the endpoint', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->deleteJson('/profil/push', [])->assertStatus(422);
});

it("deletes the user's own push subscription", function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'k', 't');

    $this->actingAs($user)
        ->deleteJson('/profil/push', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])
        ->assertNoContent();

    $this->assertDatabaseCount('push_subscriptions', 0);
});

it('blocks the shared demo account from subscribing', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);

    $this->actingAs($demo)->postJson('/profil/push', pushPayload())->assertForbidden();

    $this->assertDatabaseCount('push_subscriptions', 0);
});
