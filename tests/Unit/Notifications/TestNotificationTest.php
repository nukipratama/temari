<?php

declare(strict_types=1);

use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\TestNotification;
use App\Services\Telegram\TelegramReplies;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

it('builds the keyless test-reply message', function (): void {
    $message = new TestNotification()->toTelegram(User::factory()->create());

    expect($message->text)->toBe(TelegramReplies::test())
        ->and($message->deliveryKey)->toBeNull();
});
