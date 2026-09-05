<?php

declare(strict_types=1);

use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('blocks a demo user from an Inertia notification-preference write with a flash error', function (): void {
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->patch('/profile/notifications', [
            'notifications_enabled' => false,
            'telegram_enabled' => true,
            'push_enabled' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['demo' => 'The demo account is read-only. Nothing here can be changed.']);

    expect($user->notificationPreference()->exists())->toBeFalse();
});

it('returns a JSON 403 to a plain fetch on the notification test endpoint from the demo user', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson('/profile/notifications/test')
        ->assertStatus(403)
        ->assertJson(['message' => 'The demo account is read-only. Nothing here can be changed.']);
});

it('does not block a normal user from the same notification-preference write', function (): void {
    $user = User::factory()->create(['is_demo' => false]);

    $this->actingAs($user)
        ->patch('/profile/notifications', [
            'notifications_enabled' => false,
            'telegram_enabled' => true,
            'push_enabled' => true,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect($user->notificationPreference->notifications_enabled)->toBeFalse();
});

it('does not block a demo user from an unguarded write (interactive sandbox)', function (): void {
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)
        ->delete('/settings/zones')
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();
});

it('does not block a GET read from the demo user', function (): void {
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)->get('/profile')->assertSuccessful();
});

it('still lets the demo user log out', function (): void {
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('still lets the demo user trigger "Reread", served rule-based so it never bills', function (): void {
    Bus::fake();
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)
        ->postJson("/api/analyses/briefing_mascot_voice/{$user->id}/trigger?discriminator=2026-05-18")
        ->assertSuccessful()
        ->assertJson(['status' => 'done']);

    Bus::assertNothingDispatched();
});
