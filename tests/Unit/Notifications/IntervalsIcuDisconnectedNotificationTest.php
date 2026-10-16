<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Models\NotificationPreference;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\IntervalsIcuDisconnectedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
    $this->brokenAt = Carbon::parse('2026-10-14 06:15:00');
});

it('reaches every wired channel, even with the notifications master switch off', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect(new IntervalsIcuDisconnectedNotification($this->brokenAt)->via($user->fresh()))
        ->toBe([InAppChannel::class, TelegramChannel::class, IdempotentWebPushChannel::class]);
});

it('writes one inbox row per break, pointing at Settings', function (): void {
    $user = User::factory()->create();

    $message = new IntervalsIcuDisconnectedNotification($this->brokenAt)->toInbox($user);

    expect($message->kind)->toBe(NotificationKind::IntervalsIcuDisconnected)
        ->and($message->title)->toBe('intervals.icu stopped taking workouts')
        ->and($message->body)->toContain('reconnect it in settings')
        ->and($message->payload)->toBe(['url' => route('settings')])
        ->and($message->dedupeKey)->toBe('intervals_icu_disconnected:'.$this->brokenAt->timestamp);
});

it('links Telegram to Settings', function (): void {
    $message = new IntervalsIcuDisconnectedNotification($this->brokenAt)->toTelegram(User::factory()->create());

    expect($message->text)->toContain('intervals.icu stopped taking workouts')
        ->and($message->text)->toEndWith('Reconnect: '.route('settings'));
});
