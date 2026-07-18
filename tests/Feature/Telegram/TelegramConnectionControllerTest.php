<?php

declare(strict_types=1);

use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\TestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('sends a test notification for an active connection', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();

    $this->actingAs($user)
        ->post('/profil/telegram/test')
        ->assertRedirect()
        ->assertSessionHas('success');

    Notification::assertSentTo($user, TestNotification::class);
});

it('does not send a test notification without an active connection', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/profil/telegram/test')
        ->assertRedirect()
        ->assertSessionHas('info');

    Notification::assertNothingSent();
});

it('does not send a test notification for a revoked connection', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->revoked()->create();

    $this->actingAs($user)
        ->post('/profil/telegram/test')
        ->assertRedirect()
        ->assertSessionHas('info');

    Notification::assertNothingSent();
});

it('requires authentication to send a test notification', function (): void {
    $this->post('/profil/telegram/test')->assertRedirect(route('login'));
});

it('updates the notification preferences', function (): void {
    $user = User::factory()->create();
    $connection = TelegramConnection::factory()->for($user)->create([
        'notify_post_run' => true,
        'notify_weekly_recap' => true,
        'notify_monthly_recap' => true,
        'notify_daily_briefing' => false,
    ]);

    $this->actingAs($user)
        ->patch('/profil/telegram', [
            'notify_post_run' => false,
            'notify_weekly_recap' => true,
            'notify_monthly_recap' => false,
            'notify_daily_briefing' => true,
        ])
        ->assertRedirect();

    expect($connection->fresh()->notify_post_run)->toBeFalse()
        ->and($connection->fresh()->notify_weekly_recap)->toBeTrue()
        ->and($connection->fresh()->notify_monthly_recap)->toBeFalse()
        ->and($connection->fresh()->notify_daily_briefing)->toBeTrue();
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
