<?php

declare(strict_types=1);

use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates the notification preferences', function (): void {
    $user = User::factory()->create();
    $connection = TelegramConnection::factory()->for($user)->create([
        'notify_post_run' => true,
        'notify_weekly_recap' => true,
    ]);

    $this->actingAs($user)
        ->patch('/profil/telegram', ['notify_post_run' => false, 'notify_weekly_recap' => true])
        ->assertRedirect();

    expect($connection->fresh()->notify_post_run)->toBeFalse()
        ->and($connection->fresh()->notify_weekly_recap)->toBeTrue();
});

it('validates that both preference flags are present and boolean', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();

    $this->actingAs($user)
        ->patchJson('/profil/telegram', ['notify_post_run' => 'maybe'])
        ->assertStatus(422);
});

it('disconnects by revoking the connection', function (): void {
    $user = User::factory()->create();
    $connection = TelegramConnection::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete('/profil/telegram')
        ->assertRedirect();

    expect($connection->fresh()->isRevoked())->toBeTrue();
});

it('requires authentication', function (): void {
    $this->patch('/profil/telegram', ['notify_post_run' => true, 'notify_weekly_recap' => true])
        ->assertRedirect('/login');
});
