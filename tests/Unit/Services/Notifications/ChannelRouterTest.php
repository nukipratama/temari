<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Services\Notifications\ChannelRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
    $this->router = new ChannelRouter();
});

function userWithTelegram(): User
{
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);

    return $user;
}

function userWithPush(): User
{
    $user = User::factory()->create();
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');

    return $user;
}

it('routes to every wired channel by default', function (): void {
    $user = userWithTelegram();
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');

    expect($this->router->channelsFor($user->fresh()))
        ->toBe([TelegramChannel::class, IdempotentWebPushChannel::class]);
});

it('treats a missing preference row as all-on, so adding the columns mutes nobody', function (): void {
    $user = userWithTelegram();

    expect($user->notificationPreference)->toBeNull()
        ->and($this->router->channelsFor($user))->toBe([TelegramChannel::class]);
});

it('drops a muted channel while leaving the other alone', function (): void {
    $user = userWithTelegram();
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');
    NotificationPreference::factory()->for($user)->create(['telegram_enabled' => false]);

    expect($this->router->channelsFor($user->fresh()))->toBe([IdempotentWebPushChannel::class]);
});

it('drops push when push is the muted one', function (): void {
    $user = userWithTelegram();
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');
    NotificationPreference::factory()->for($user)->create(['push_enabled' => false]);

    expect($this->router->channelsFor($user->fresh()))->toBe([TelegramChannel::class]);
});

/**
 * The whole point of a mute rather than a disconnect: the link survives, so
 * un-muting is one tap with no re-auth.
 */
it('leaves the connection intact when a channel is muted', function (): void {
    $user = userWithTelegram();
    NotificationPreference::factory()->for($user)->create(['telegram_enabled' => false]);

    expect($this->router->channelsFor($user->fresh()))->toBe([])
        ->and($user->telegramConnection->isRevoked())->toBeFalse();
});

it('cannot reach a user whose only channel is muted', function (): void {
    $user = userWithTelegram();
    NotificationPreference::factory()->for($user)->create(['telegram_enabled' => false]);

    expect($this->router->canReach($user->fresh()))->toBeFalse();
});

/**
 * Only AnalysisReadyNotification enforced this before; the other five
 * reachability checks would route to a channel that could not possibly send.
 */
it('will not route to Telegram without a configured bot token', function (): void {
    config(['services.telegram.bot_token' => '']);
    $user = userWithTelegram();

    expect($this->router->channelsFor($user))->toBe([]);
});

it('still routes to push when Telegram is unconfigured', function (): void {
    config(['services.telegram.bot_token' => '']);
    $user = userWithPush();

    expect($this->router->channelsFor($user->fresh()))->toBe([IdempotentWebPushChannel::class]);
});

describe('scopeReachable', function (): void {
    it('selects users reachable on either channel', function (): void {
        $viaTelegram = userWithTelegram();
        $viaPush = userWithPush();
        User::factory()->create(); // wired to nothing

        $ids = User::query()->where($this->router->scopeReachable(...))->pluck('id');

        expect($ids)->toHaveCount(2)
            ->and($ids)->toContain($viaTelegram->id)
            ->and($ids)->toContain($viaPush->id);
    });

    /**
     * Without this the streak command enqueues a notification per candidate and
     * each via() returns [] — silent no-op work every Saturday.
     */
    it('excludes a user whose only channel is muted', function (): void {
        $user = userWithTelegram();
        NotificationPreference::factory()->for($user)->create(['telegram_enabled' => false]);

        expect(User::query()->where($this->router->scopeReachable(...))->count())->toBe(0);
    });

    it('keeps a user muted on one channel but wired on the other', function (): void {
        $user = userWithTelegram();
        $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');
        NotificationPreference::factory()->for($user)->create(['telegram_enabled' => false]);

        expect(User::query()->where($this->router->scopeReachable(...))->pluck('id')->all())
            ->toBe([$user->id]);
    });

    it('excludes Telegram-only users when no bot token is configured', function (): void {
        config(['services.telegram.bot_token' => '']);
        userWithTelegram();

        expect(User::query()->where($this->router->scopeReachable(...))->count())->toBe(0);
    });

    // The query filter and the per-user check must not disagree, or the command
    // selects users the notification then refuses to send to.
    it('agrees with channelsFor for every combination', function (): void {
        $both = userWithTelegram();
        $both->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');
        $telegramOnly = userWithTelegram();
        $pushOnly = userWithPush();
        $muted = userWithTelegram();
        NotificationPreference::factory()->for($muted)->create(['telegram_enabled' => false]);
        $none = User::factory()->create();

        $selected = User::query()->where($this->router->scopeReachable(...))->pluck('id')->all();

        foreach ([$both, $telegramOnly, $pushOnly, $muted, $none] as $user) {
            expect(in_array($user->id, $selected, true))
                ->toBe($this->router->canReach($user->fresh()));
        }
    });
});
