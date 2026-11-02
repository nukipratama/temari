<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\EasyTooHardNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushMessage;

uses(RefreshDatabase::class);

it('routes to web push alongside the inbox for a subscribed athlete', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(new EasyTooHardNotification('2026-11-02', EasyTooHardNotification::ONE_MORE, false)->via($user))
        ->toBe([InAppChannel::class, IdempotentWebPushChannel::class]);
});

it('sends nothing once the master switch is off', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect(new EasyTooHardNotification('2026-11-02', EasyTooHardNotification::SET, false)->via($user))->toBe([]);
});

it('says what the week\'s hot easy days cost next week, keyed once per week and state', function (string $state, bool $egregious, string $title, string $body): void {
    $user = User::factory()->create();

    $message = new EasyTooHardNotification('2026-11-02', $state, $egregious)->toInbox($user);

    expect($message->kind)->toBe(NotificationKind::EasyTooHard)
        ->and($message->title)->toBe($title)
        ->and($message->body)->toBe($body)
        ->and($message->payload['url'])->toBe(route('plan'))
        ->and($message->dedupeKey)->toBe(EasyTooHardNotification::dedupeKeyFor(Carbon::parse('2026-11-02'), $state))
        ->and($message->dedupeKey)->toBe("easy_too_hard:2026-11-02:{$state}");
})->with([
    'one more' => [EasyTooHardNotification::ONE_MORE, false, 'An easy run ran hot', 'it went past your heart-rate cap. one more easy day like it this week costs next week a quality session.'],
    'set by two' => [EasyTooHardNotification::SET, false, 'Next week loses a quality session', 'a second easy day went past your heart-rate cap, so next week carries one less quality session. keep the rest of this week easy.'],
    'set by one far over' => [EasyTooHardNotification::SET, true, 'Next week loses a quality session', 'an easy run went far enough past your heart-rate cap to cost next week a quality session on its own. keep the rest of this week easy.'],
]);

it('carries the same copy on telegram and web push', function (): void {
    $user = User::factory()->create();
    $notification = new EasyTooHardNotification('2026-11-02', EasyTooHardNotification::ONE_MORE, false);

    expect($notification->toTelegram($user)->text)->toContain('An easy run ran hot')->toContain(route('plan'))
        ->and($notification->toWebPush($user, $notification))->toBeInstanceOf(WebPushMessage::class);
});

it('goes stale once the week it warns about has ended', function (): void {
    expect(new EasyTooHardNotification('2026-11-02', EasyTooHardNotification::SET, false)->staleAfter?->toDateTimeString())->toBe('2026-11-08 23:59:59');
});
