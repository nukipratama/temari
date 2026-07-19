<?php

declare(strict_types=1);

use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\TestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('requires authentication', function (): void {
    $this->post('/profil/notifikasi/test')->assertRedirect(route('login'));
});

it('sends a test notification when a Telegram connection is wired', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();

    $this->actingAs($user)->post('/profil/notifikasi/test')->assertRedirect()->assertSessionHas('success');

    Notification::assertSentTo($user, TestNotification::class);
});

it('sends a test notification when only a web-push subscription is wired', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    $this->actingAs($user)->post('/profil/notifikasi/test')->assertRedirect()->assertSessionHas('success');

    Notification::assertSentTo($user, TestNotification::class);
});

it('does not send without any wired channel', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/profil/notifikasi/test')->assertRedirect()->assertSessionHas('info');

    Notification::assertNothingSent();
});

it('does not send with only a revoked connection', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->revoked()->create();

    $this->actingAs($user)->post('/profil/notifikasi/test')->assertRedirect()->assertSessionHas('info');

    Notification::assertNothingSent();
});

it('blocks the shared demo account', function (): void {
    Notification::fake();
    $demo = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($demo)->create();

    $this->actingAs($demo)->postJson('/profil/notifikasi/test')->assertForbidden();

    Notification::assertNothingSent();
});
