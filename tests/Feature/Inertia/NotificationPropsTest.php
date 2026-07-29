<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\Inertia\NotificationProps;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function notificationPropsFor(?User $user): array
{
    return app(NotificationProps::class)->forUser($user);
}

it('keeps every prop a closure so a partial reload can skip it', function (): void {
    $props = notificationPropsFor(User::factory()->create());

    foreach (['telegramConnected', 'webPushSubscribed'] as $key) {
        expect($props[$key])->toBeInstanceOf(Closure::class);
    }
});

it('reports a guest as unreachable on both channels', function (): void {
    $props = notificationPropsFor(null);

    expect(($props['telegramConnected'])())->toBeFalse()
        ->and(($props['webPushSubscribed'])())->toBeFalse();
});

it('reports Telegram as connected only when it is wired and un-muted', function (): void {
    config(['services.telegram.bot_token' => 'test-token']);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);

    expect((notificationPropsFor($user)['telegramConnected'])())->toBeTrue();
});

it('reports a muted Telegram channel as not connected, so the button never lies', function (): void {
    config(['services.telegram.bot_token' => 'test-token']);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    NotificationPreference::factory()->for($user)->create(['telegram_enabled' => false]);

    expect((notificationPropsFor($user->fresh())['telegramConnected'])())->toBeFalse();
});

it('reports a revoked Telegram connection as not connected', function (): void {
    config(['services.telegram.bot_token' => 'test-token']);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create()->markRevoked();

    expect((notificationPropsFor($user->fresh())['telegramConnected'])())->toBeFalse();
});

it('reports web push as subscribed once a subscription exists', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/zzz', str_repeat('a', 87), str_repeat('b', 22));

    expect((notificationPropsFor($user->fresh())['webPushSubscribed'])())->toBeTrue();
});

it('reports a muted push channel as not subscribed', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['push_enabled' => false]);
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/zzz', str_repeat('a', 87), str_repeat('b', 22));

    expect((notificationPropsFor($user->fresh())['webPushSubscribed'])())->toBeFalse();
});
