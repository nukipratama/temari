<?php

declare(strict_types=1);

use App\Enums\RaceOutcome;
use App\Models\NotificationPreference;
use App\Models\RaceGoal;
use App\Models\User;
use App\Notifications\RaceOutcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-05 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

function raceYesterday(User $user, RaceOutcome $outcome = RaceOutcome::Pending): RaceGoal
{
    return RaceGoal::factory()->for($user)->completed()->create([
        'race_date' => '2026-10-04',
        'distance_m' => 21_097,
        'outcome' => $outcome,
    ]);
}

it('asks each athlete whose race was yesterday how it went', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $race = raceYesterday($user);
    raceYesterday(User::factory()->create());

    $this->artisan('race:ask-outcome')
        ->expectsOutputToContain('Asked 2 users how their race went.')
        ->assertSuccessful();

    Notification::assertSentTo($user, RaceOutcomeNotification::class, fn (RaceOutcomeNotification $n): bool => $n->race->is($race));
});

it('asks only once for the same race', function (): void {
    Queue::fake();
    $race = raceYesterday(User::factory()->create());

    $this->artisan('race:ask-outcome')->assertSuccessful();
    $this->artisan('race:ask-outcome')->expectsOutputToContain('Asked 0 users')->assertSuccessful();

    $queued = Queue::pushed(SendQueuedNotifications::class)
        ->filter(fn (SendQueuedNotifications $job): bool => $job->notification instanceof RaceOutcomeNotification);
    expect($queued)->not->toBeEmpty()
        ->and($queued->map(fn (SendQueuedNotifications $job): string => $job->notification->id)->unique())->toHaveCount(1)
        ->and($race->fresh()->outcome_asked_for_date?->toDateString())->toBe('2026-10-04');
});

it('releases the claim when dispatch throws, so the next run sends', function (): void {
    $user = User::factory()->create();
    $race = raceYesterday($user);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Redis is down'));

    expect(fn () => $this->artisan('race:ask-outcome')->run())->toThrow(RuntimeException::class, 'Redis is down');
    expect($race->fresh()->outcome_asked_for_date)->toBeNull();

    Notification::fake();
    $this->artisan('race:ask-outcome')->assertSuccessful();

    Notification::assertSentToTimes($user, RaceOutcomeNotification::class, 1);
    expect($race->fresh()->outcome_asked_for_date?->toDateString())->toBe('2026-10-04');
});

it('claims a rescheduled race again for its new date', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $race = raceYesterday($user);
    $this->artisan('race:ask-outcome')->assertSuccessful();

    $race->update(['race_date' => '2026-10-11']);
    Carbon::setTestNow('2026-10-12 09:00:00');

    $this->artisan('race:ask-outcome')->assertSuccessful();
    $this->artisan('race:ask-outcome')->expectsOutputToContain('Asked 0 users')->assertSuccessful();

    Notification::assertSentToTimes($user, RaceOutcomeNotification::class, 2);
    expect($race->fresh()->outcome_asked_for_date?->toDateString())->toBe('2026-10-11');
});

it('does not ask about a race that is already answered, legacy, or on another day', function (RaceGoal $race): void {
    Notification::fake();

    $this->artisan('race:ask-outcome')->assertSuccessful();

    Notification::assertNothingSent();
})->with([
    'confirmed' => fn () => raceYesterday(User::factory()->create(), RaceOutcome::Confirmed),
    'did not run' => fn () => raceYesterday(User::factory()->create(), RaceOutcome::DidNotRun),
    'legacy without an outcome' => fn () => RaceGoal::factory()->for(User::factory()->create())->completed()->create(['race_date' => '2026-10-04', 'outcome' => null]),
    'two days ago' => fn () => RaceGoal::factory()->for(User::factory()->create())->completed()->create(['race_date' => '2026-10-03', 'outcome' => RaceOutcome::Pending]),
    'today' => fn () => RaceGoal::factory()->for(User::factory()->create())->create(['race_date' => '2026-10-05', 'outcome' => RaceOutcome::Pending]),
]);

it('never asks the demo account or an athlete with notifications off', function (): void {
    Notification::fake();
    raceYesterday(User::factory()->create(['is_demo' => true]));
    $quiet = User::factory()->create();
    NotificationPreference::factory()->for($quiet)->create(['notifications_enabled' => false]);
    raceYesterday($quiet);

    $this->artisan('race:ask-outcome')->expectsOutputToContain('Asked 0 users')->assertSuccessful();

    Notification::assertNothingSent();
});
