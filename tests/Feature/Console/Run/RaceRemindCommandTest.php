<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\RaceGoal;
use App\Models\User;
use App\Notifications\RaceTomorrowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-05-23 18:00:00'));

afterEach(fn () => Carbon::setTestNow());

function raceTomorrow(User $user): RaceGoal
{
    return RaceGoal::factory()->for($user)->create([
        'race_date' => '2026-05-24',
        'distance_m' => 21097,
    ]);
}

it('tells each athlete whose race is tomorrow', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $race = raceTomorrow($user);
    $other = User::factory()->create();
    raceTomorrow($other);

    $this->artisan('race:remind')
        ->expectsOutputToContain('Dispatched race-day reminder to 2 users.')
        ->assertSuccessful();

    Notification::assertSentTo(
        $user,
        RaceTomorrowNotification::class,
        fn (RaceTomorrowNotification $notification): bool => $notification->race->is($race),
    );
    Notification::assertSentTo($other, RaceTomorrowNotification::class);
});

it('says nothing a second time for the same race', function (): void {
    Queue::fake();
    $race = raceTomorrow(User::factory()->create());

    $this->artisan('race:remind')->assertSuccessful();
    $this->artisan('race:remind')->expectsOutputToContain('Dispatched race-day reminder to 0 users.')->assertSuccessful();

    $queued = Queue::pushed(SendQueuedNotifications::class)
        ->filter(fn (SendQueuedNotifications $job): bool => $job->notification instanceof RaceTomorrowNotification);
    expect($queued)->not->toBeEmpty()
        ->and($queued->map(fn (SendQueuedNotifications $job): string => $job->notification->id)->unique())->toHaveCount(1)
        ->and($race->fresh()->reminded_at)->not->toBeNull();
});

it('releases the claim when dispatch throws, so the next run sends', function (): void {
    $user = User::factory()->create();
    $race = raceTomorrow($user);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Redis is down'));

    expect(fn () => $this->artisan('race:remind')->run())->toThrow(RuntimeException::class, 'Redis is down');
    expect($race->fresh()->reminded_at)->toBeNull();

    Notification::fake();
    $this->artisan('race:remind')->assertSuccessful();

    Notification::assertSentToTimes($user, RaceTomorrowNotification::class, 1);
    expect($race->fresh()->reminded_at)->not->toBeNull();
});

it('skips a race that is not tomorrow', function (string $raceDate): void {
    Notification::fake();

    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['race_date' => $raceDate]);

    $this->artisan('race:remind')->assertSuccessful();

    Notification::assertNothingSent();
})->with([
    'today' => '2026-05-23',
    'in a week' => '2026-05-30',
    'already run' => '2026-05-01',
]);

it('skips a race the athlete has already retired', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create([
        'race_date' => '2026-05-24',
        'completed_at' => Carbon::now(),
    ]);

    $this->artisan('race:remind')->assertSuccessful();

    Notification::assertNothingSent();
});

it('leaves alone an athlete who turned the master switch off', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);
    raceTomorrow($user);

    $this->artisan('race:remind')
        ->expectsOutputToContain('Dispatched race-day reminder to 0 users.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('excludes the demo account, like every other kickoff', function (): void {
    Notification::fake();

    $demo = User::factory()->create(['is_demo' => true]);
    raceTomorrow($demo);

    $this->artisan('race:remind')
        ->expectsOutputToContain('Dispatched race-day reminder to 0 users.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});
