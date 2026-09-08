<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\UnlockGrantedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function unlockCelebration(): array
{
    return [
        'unlock_key' => 'accessory.shoes_legendary',
        'name' => 'Legendary Shoes',
        'icon' => 'mdi:shoe-sneaker',
        'is_major' => true,
    ];
}

// An unlock is a record, not an interruption: a batch of them would otherwise
// fire a batch of Telegram messages and lock-screen pushes.
it('routes to the inbox only, even for a user wired on every outbound channel', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');

    expect(new UnlockGrantedNotification(unlockCelebration())->via($user->fresh()))
        ->toBe([InAppChannel::class]);
});

it('routes the demo user to the inbox, like everyone else', function (): void {
    $user = User::factory()->create(['is_demo' => true]);

    expect(new UnlockGrantedNotification(unlockCelebration())->via($user))->toBe([InAppChannel::class]);
});

// The payload is the flash payload verbatim, so the same takeover component can
// replay the celebration from the inbox weeks later. It also carries the deep
// link to the accessory shelf, without which the row renders no open control.
it('carries the whole celebration payload and the shelf deep link, keyed on the unlock', function (): void {
    $message = new UnlockGrantedNotification(unlockCelebration())->toInbox(User::factory()->create());

    expect($message->kind)->toBe(NotificationKind::Unlock)
        ->and($message->title)->toBe('Unlocked: Legendary Shoes')
        ->and($message->payload)->toBe(unlockCelebration() + ['url' => route('profile')])
        ->and($message->dedupeKey)->toBe('unlock:accessory.shoes_legendary');
});
