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
    ]);

    $this->actingAs($user)
        ->patch('/profil/notifikasi', [
            'post_run' => false,
            'weekly_recap' => false,
            'monthly_recap' => false,
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
    $this->patch('/profil/notifikasi', ['post_run' => true, 'weekly_recap' => true, 'monthly_recap' => true])
        ->assertRedirect('/login');
});
