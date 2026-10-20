<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Notifications\StreakReminderNotification;
use App\Services\Ops\MaintainerAlerter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

// Telegram routing now requires a configured bot token, the same precondition
// AnalysisReadyNotification always enforced. Unifying the six reachability
// checks into ChannelRouter applied it everywhere, so these tests have to
// satisfy it rather than route to a channel that could not actually send.
beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
});

// Saturday of the open week; the open week ends Sunday 2026-05-24, the
// immediately-prior (last fully-closed) week ends Sunday 2026-05-17.
beforeEach(fn () => Carbon::setTestNow('2026-05-23 18:00:00'));

afterEach(fn () => Carbon::setTestNow());

it('nudges a user with a live streak and no run yet this week', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $this->artisan('streak:remind')
        ->expectsOutputToContain('Dispatched streak-at-risk reminder to 1 users.')
        ->assertSuccessful();

    Notification::assertSentTo($user, StreakReminderNotification::class, fn (StreakReminderNotification $notification): bool => $notification->streakWeeks === 1);
});

// The command iterates reachable users, so a push-only user is a candidate.
it('nudges a push-only user who has never linked Telegram', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $this->artisan('streak:remind')
        ->expectsOutputToContain('Dispatched streak-at-risk reminder to 1 users.')
        ->assertSuccessful();

    Notification::assertSentTo($user, StreakReminderNotification::class);
});

it('skips a user it cannot reach', function (Closure $makeUnreachable): void {
    Notification::fake();

    $user = User::factory()->create();
    $makeUnreachable($user);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $this->artisan('streak:remind')
        ->expectsOutputToContain('Dispatched streak-at-risk reminder to 0 users.')
        ->assertSuccessful();

    Notification::assertNothingSent();
})->with([
    'no channel wired at all' => [function (User $user): void {
    }],
    'the notification master switch is off' => [function (User $user): void {
        TelegramConnection::factory()->for($user)->create();
        NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);
    }],
]);

it('counts a user wired on both channels only once', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $this->artisan('streak:remind')
        ->expectsOutputToContain('Dispatched streak-at-risk reminder to 1 users.')
        ->assertSuccessful();

    Notification::assertSentToTimes($user, StreakReminderNotification::class, 1);
});

it('skips a user who already ran this week', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-24', 'runs' => 1]);

    $this->artisan('streak:remind')->assertSuccessful();

    Notification::assertNothingSent();
});

it('skips the demo user', function (): void {
    Notification::fake();

    $demo = User::factory()->demo()->create();
    TelegramConnection::factory()->for($demo)->create();
    WeeklySnapshot::factory()->for($demo)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $this->artisan('streak:remind')
        ->expectsOutputToContain('Dispatched streak-at-risk reminder to 0 users.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('skips a user with no live streak', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    // Last run two weeks back: a full week has closed since, so the streak is
    // already broken (consecutiveWeekStreak returns 0).
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10', 'runs' => 2]);

    $this->artisan('streak:remind')->assertSuccessful();

    Notification::assertNothingSent();
});

it('does not double-send within the same at-risk week', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $this->artisan('streak:remind')->assertSuccessful();
    $this->artisan('streak:remind')
        ->expectsOutputToContain('Dispatched streak-at-risk reminder to 0 users.')
        ->assertSuccessful();

    Notification::assertSentToTimes($user, StreakReminderNotification::class, 1);
});

it('releases the week claim when dispatch throws, so the next run sends', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Redis is down'));
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('athletesFailed')->once()->with('streak:remind', 1);
    $this->app->instance(MaintainerAlerter::class, $alerter);

    $this->artisan('streak:remind')->assertSuccessful();
    $this->assertDatabaseMissing('streak_reminders', ['user_id' => $user->id]);

    Notification::fake();
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('athletesRecovered')->once()->with('streak:remind');
    $this->app->instance(MaintainerAlerter::class, $alerter);
    $this->artisan('streak:remind')
        ->expectsOutputToContain('Dispatched streak-at-risk reminder to 1 users.')
        ->assertSuccessful();

    Notification::assertSentToTimes($user, StreakReminderNotification::class, 1);
    $this->assertDatabaseHas('streak_reminders', ['user_id' => $user->id, 'week_ending' => '2026-05-24']);
});

it('keeps reminding the other users when one user throws, and alerts once', function (): void {
    $users = User::factory()->count(2)->create();
    foreach ($users as $user) {
        TelegramConnection::factory()->for($user)->create();
        WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);
    }
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Redis is down'));
    Notification::shouldReceive('send')->once();
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('athletesFailed')->once()->with('streak:remind', 1);
    $this->app->instance(MaintainerAlerter::class, $alerter);

    $this->artisan('streak:remind')
        ->expectsOutputToContain('Dispatched streak-at-risk reminder to 1 users.')
        ->assertSuccessful();

    $this->assertDatabaseCount('streak_reminders', 1);
});

/**
 * Without mute-awareness in the query filter this command enqueues a
 * notification per candidate whose via() then returns [] — silent no-op work
 * every Saturday rather than a visible failure.
 */
it('does not select a user whose only channel is muted', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    NotificationPreference::factory()->for($user)->create(['telegram_enabled' => false]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $this->artisan('streak:remind')->assertSuccessful();

    Notification::assertNothingSent();
});

it('still nudges a user muted on one channel but wired on the other', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');
    NotificationPreference::factory()->for($user)->create(['telegram_enabled' => false]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $this->artisan('streak:remind')->assertSuccessful();

    Notification::assertSentTo($user, StreakReminderNotification::class);
});
