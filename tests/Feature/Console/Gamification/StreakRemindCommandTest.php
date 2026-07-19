<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Notifications\StreakReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

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

it('skips a user opted out of the weekly recap', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    NotificationPreference::factory()->for($user)->create(['weekly_recap' => false]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $this->artisan('streak:remind')
        ->expectsOutputToContain('Dispatched streak-at-risk reminder to 0 users.')
        ->assertSuccessful();

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
