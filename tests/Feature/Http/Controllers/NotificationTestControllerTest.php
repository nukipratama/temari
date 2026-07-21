<?php

declare(strict_types=1);

use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\TestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

// Telegram routing now requires a configured bot token, the same precondition
// AnalysisReadyNotification always enforced. Unifying the six reachability
// checks into ChannelRouter applied it everywhere, so these tests have to
// satisfy it rather than route to a channel that could not actually send.
beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
});

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

it('cools down after a send so a second tap does not fire again', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);

    $this->actingAs($user)->post('/profil/notifikasi/test')->assertRedirect();
    Notification::assertSentTimes(TestNotification::class, 1);

    $this->actingAs($user)->post('/profil/notifikasi/test')->assertRedirect();

    // Still one: the second tap is swallowed by the cooldown, not sent twice.
    Notification::assertSentTimes(TestNotification::class, 1);
});

it('cools per user, so one account cannot mute another', function (): void {
    Notification::fake();
    $first = User::factory()->create();
    $second = User::factory()->create();
    TelegramConnection::factory()->for($first)->create(['revoked_at' => null]);
    TelegramConnection::factory()->for($second)->create(['revoked_at' => null]);

    $this->actingAs($first)->post('/profil/notifikasi/test')->assertRedirect();
    $this->actingAs($second)->post('/profil/notifikasi/test')->assertRedirect();

    Notification::assertSentTimes(TestNotification::class, 2);
});
