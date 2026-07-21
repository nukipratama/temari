<?php

declare(strict_types=1);

use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\TestNotification;
use App\Services\Telegram\TelegramReplies;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Telegram routing now requires a configured bot token, the same precondition
// AnalysisReadyNotification always enforced. Unifying the six reachability
// checks into ChannelRouter applied it everywhere, so these tests have to
// satisfy it rather than route to a channel that could not actually send.
beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
});

it('routes to Telegram when a connection is present', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();

    expect(new TestNotification()->via($user))->toBe([TelegramChannel::class]);
});

it('routes nowhere without a connection', function (): void {
    expect(new TestNotification()->via(User::factory()->create()))->toBe([]);
});

it('routes nowhere over a revoked connection', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->revoked()->create();

    expect(new TestNotification()->via($user))->toBe([]);
});

it('routes nowhere for the demo user', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($user)->create();

    expect(new TestNotification()->via($user))->toBe([]);
});

it('routes to web push when the user has a subscription', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(new TestNotification()->via($user))->toBe([IdempotentWebPushChannel::class]);
});

it('builds the keyless test-reply message', function (): void {
    $message = new TestNotification()->toTelegram(User::factory()->create());

    expect($message->text)->toBe(TelegramReplies::test())
        ->and($message->deliveryKey)->toBeNull();
});

it('builds a titled, high-urgency web push test message', function (): void {
    $notification = new TestNotification();

    $message = $notification->toWebPush(User::factory()->create(), $notification);
    $payload = $message->toArray();

    expect($payload['title'])->toBe('🔔 Tes notifikasi')
        ->and($payload['body'])->toBe(TelegramReplies::test())
        ->and($message->getOptions())->toBe(['urgency' => 'high']);
});
