<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Enums\SessionType;
use App\Models\NotificationPreference;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\RaceTomorrowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-05-23 18:00:00'));

afterEach(fn () => Carbon::setTestNow());

function raceFor(User $user, ?string $name = null): RaceGoal
{
    return RaceGoal::factory()->for($user)->create([
        'race_date' => '2026-05-24',
        'distance_m' => 21097,
        'name' => $name,
    ]);
}

it('routes to web push alongside the inbox for a subscribed athlete', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(new RaceTomorrowNotification(raceFor($user))->via($user))
        ->toBe([InAppChannel::class, IdempotentWebPushChannel::class]);
});

it('sends nothing at all once the master switch is off', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect(new RaceTomorrowNotification(raceFor($user))->via($user))->toBe([]);
});

it('names the race and its distance, and points at the race page', function (): void {
    $user = User::factory()->create();
    $race = raceFor($user, 'jakarta half');

    $message = new RaceTomorrowNotification($race)->toInbox($user);

    expect($message->kind)->toBe(NotificationKind::RaceTomorrow)
        ->and($message->title)->toBe('race day is tomorrow')
        ->and($message->body)->toStartWith('jakarta half is tomorrow — 21.1 km.')
        ->and($message->body)->toContain('lay your kit out tonight')
        ->and($message->payload['url'])->toBe(route('race'))
        ->and($message->dedupeKey)->toBe('race_tomorrow:'.$race->id.':2026-05-24');
});

it('falls back to the bare distance when the race has no name', function (): void {
    $user = User::factory()->create();

    expect(new RaceTomorrowNotification(raceFor($user))->toInbox($user)->body)
        ->toStartWith('your 21.1 km is tomorrow.');
});

it('says the plan back when today is the taper rest', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create([
        'date' => '2026-05-23',
        'session_type' => SessionType::Rest,
    ]);

    expect(new RaceTomorrowNotification(raceFor($user))->toInbox($user)->body)
        ->toContain('the plan rests you today');
});

it('says nothing about the taper when today is not a rest day', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create([
        'date' => '2026-05-23',
        'session_type' => SessionType::Easy,
    ]);

    expect(new RaceTomorrowNotification(raceFor($user))->toInbox($user)->body)
        ->not->toContain('the plan rests you today');
});
