<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\FitnessImprovedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function improvedNote(float $raceM = 10_000.0, int $basisM = 5_000): FitnessImprovedNotification
{
    return new FitnessImprovedNotification($raceM, 3_570, 3_640, $basisM, '2026-08-26', '2026-10-05');
}

it('routes to web push alongside the inbox for a subscribed athlete', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(improvedNote()->via($user))->toBe([InAppChannel::class, IdempotentWebPushChannel::class]);
});

it('sends nothing once the master switch is off', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect(improvedNote()->via($user))->toBe([]);
});

it('names the new time, the gain and the effort it rests on, and points at the race page', function (): void {
    $user = User::factory()->create();

    $message = improvedNote()->toInbox($user);

    expect($message->kind)->toBe(NotificationKind::FitnessImproved)
        ->and($message->title)->toBe('your supported 10K just got quicker')
        ->and($message->body)->toBe('your recent runs now support 59:30 for the 10K, 1:10 quicker than when i last told you. it rests on your 5K on aug 26, and your easy and long-run paces move up with it.')
        ->and($message->payload)->toBe(['url' => route('race'), 'supported_time_sec' => 3_570])
        ->and($message->dedupeKey)->toBe('fitness_improved:2026-10-05');
});

it('names a half marathon and an off-standard effort in words', function (): void {
    $message = improvedNote(21_098.0, 7_300)->toInbox(User::factory()->create());

    expect($message->title)->toBe('your supported half marathon just got quicker')
        ->and($message->body)->toContain('your 7.3 km on aug 26');
});

it('keeps the push for three days and lets a newer note replace an older one', function (): void {
    $user = User::factory()->create();

    $options = improvedNote()->toWebPush($user, improvedNote())->getOptions();

    expect($options)->toBe(['TTL' => 3 * 86400, 'topic' => 'fitness']);
});

it('sends the same note to Telegram with a link to the race page', function (): void {
    $text = improvedNote()->toTelegram(User::factory()->create())->text;

    expect($text)->toStartWith('your supported 10K just got quicker')
        ->and($text)->toEndWith('Open Temari: '.route('race'));
});
