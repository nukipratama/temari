<?php

declare(strict_types=1);

use App\Models\InboxNotification;
use App\Models\RaceGoal;
use App\Models\User;
use App\Notifications\RaceTomorrowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

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
    Notification::fake();

    $user = User::factory()->create();
    $race = raceTomorrow($user);

    InboxNotification::factory()->for($user)->create([
        'kind' => 'race_tomorrow',
        'dedupe_key' => RaceTomorrowNotification::dedupeKeyFor($race),
    ]);

    $this->artisan('race:remind')
        ->expectsOutputToContain('Dispatched race-day reminder to 0 users.')
        ->assertSuccessful();

    Notification::assertNothingSent();
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

it('excludes the demo account, like every other kickoff', function (): void {
    Notification::fake();

    $demo = User::factory()->create(['is_demo' => true]);
    raceTomorrow($demo);

    $this->artisan('race:remind')
        ->expectsOutputToContain('Dispatched race-day reminder to 0 users.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});
