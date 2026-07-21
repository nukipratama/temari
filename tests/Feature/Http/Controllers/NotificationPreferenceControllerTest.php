<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a preference row on the first write', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profil/notifikasi', [
            'post_run' => false,
            'weekly_recap' => true,
            'monthly_recap' => false,
            'telegram_enabled' => true,
            'push_enabled' => true,
        ])
        ->assertRedirect();

    $preference = $user->notificationPreference()->first();
    expect($preference)->not->toBeNull()
        ->and($preference->post_run)->toBeFalse()
        ->and($preference->weekly_recap)->toBeTrue()
        ->and($preference->monthly_recap)->toBeFalse();
});

it('updates the existing preference row without creating a second', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create([
        'post_run' => true,
        'weekly_recap' => true,
        'monthly_recap' => true,
            'telegram_enabled' => true,
            'push_enabled' => true,
    ]);

    $this->actingAs($user)
        ->patch('/profil/notifikasi', [
            'post_run' => false,
            'weekly_recap' => false,
            'monthly_recap' => false,
            'telegram_enabled' => true,
            'push_enabled' => true,
        ])
        ->assertRedirect();

    expect($user->notificationPreference()->count())->toBe(1)
        ->and($user->notificationPreference()->first()->post_run)->toBeFalse();
});

it('validates that the flags are present and boolean', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/profil/notifikasi', ['post_run' => 'maybe'])
        ->assertStatus(422);
});

it('requires authentication', function (): void {
    $this->patch('/profil/notifikasi', ['post_run' => true, 'weekly_recap' => true, 'monthly_recap' => true, 'telegram_enabled' => true, 'push_enabled' => true])
        ->assertRedirect('/login');
});

/**
 * Every field is required because the client always sends the complete state.
 * The toggles live in two different groups on the page now, so a partial write
 * would leave updateOrCreate holding whatever the other group had before —
 * which reads to the user as a toggle that did not stick.
 */
it('rejects a partial write that omits the channel mutes', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profil/notifikasi', [
            'post_run' => true,
            'weekly_recap' => true,
            'monthly_recap' => true,
        ])
        ->assertSessionHasErrors(['telegram_enabled', 'push_enabled']);
});

it('persists the channel mutes alongside the per-type opt-ins', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profil/notifikasi', [
            'post_run' => true,
            'weekly_recap' => true,
            'monthly_recap' => true,
            'telegram_enabled' => false,
            'push_enabled' => true,
        ])
        ->assertSessionHasNoErrors();

    $preference = $user->fresh()->notificationPreference;

    expect($preference->telegram_enabled)->toBeFalse()
        ->and($preference->push_enabled)->toBeTrue()
        ->and($preference->post_run)->toBeTrue();
});
