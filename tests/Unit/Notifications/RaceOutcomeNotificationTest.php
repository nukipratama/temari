<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Enums\RaceOutcome;
use App\Models\NotificationPreference;
use App\Models\RaceGoal;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\RaceOutcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushMessage;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-05 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

function pastRace(User $user, ?string $name = null): RaceGoal
{
    return RaceGoal::factory()->for($user)->completed()->create([
        'race_date' => '2026-10-04',
        'distance_m' => 10_000,
        'name' => $name,
        'outcome' => RaceOutcome::Pending,
    ]);
}

it('routes to web push alongside the inbox for a subscribed athlete', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(new RaceOutcomeNotification(pastRace($user))->via($user))
        ->toBe([InAppChannel::class, IdempotentWebPushChannel::class]);
});

it('sends nothing once the master switch is off', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect(new RaceOutcomeNotification(pastRace($user))->via($user))->toBe([]);
});

it('asks neutrally, claims nothing, and points at the race page', function (): void {
    $user = User::factory()->create();
    $race = pastRace($user, 'jakarta 10k');

    $message = new RaceOutcomeNotification($race)->toInbox($user);

    expect($message->kind)->toBe(NotificationKind::RaceOutcome)
        ->and($message->title)->toBe('How did your race go?')
        ->and($message->body)->toStartWith('jakarta 10k was yesterday.')
        ->and($message->body)->toContain('nothing is counted until you do')
        ->and($message->payload['url'])->toBe(route('race'))
        ->and($message->dedupeKey)->toBe('race_outcome:'.$race->id.':2026-10-04');
});

it('falls back to the bare distance when the race has no name', function (): void {
    $user = User::factory()->create();

    expect(new RaceOutcomeNotification(pastRace($user))->toInbox($user)->body)->toStartWith('your 10 km was yesterday.');
});

it('carries the same copy on telegram and web push', function (): void {
    $user = User::factory()->create();
    $notification = new RaceOutcomeNotification(pastRace($user, 'jakarta 10k'));

    expect($notification->toTelegram($user)->text)->toContain('How did your race go?')->toContain(route('race'))
        ->and($notification->toWebPush($user, $notification))->toBeInstanceOf(WebPushMessage::class);
});
