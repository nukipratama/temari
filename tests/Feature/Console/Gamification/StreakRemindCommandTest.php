<?php

declare(strict_types=1);

use App\Jobs\Telegram\SendStreakReminderJob;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// Saturday of the open week; the open week ends Sunday 2026-05-24, the
// immediately-prior (last fully-closed) week ends Sunday 2026-05-17.
beforeEach(fn () => Carbon::setTestNow('2026-05-23 18:00:00'));

afterEach(fn () => Carbon::setTestNow());

it('nudges a user with a live streak and no run yet this week', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['notify_weekly_recap' => true]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $this->artisan('streak:remind')
        ->expectsOutputToContain('Dispatched streak-at-risk reminder to 1 users.')
        ->assertSuccessful();

    Queue::assertPushed(SendStreakReminderJob::class, fn (SendStreakReminderJob $job): bool => $job->userId === $user->id && $job->streakWeeks === 1);
});

it('skips a user who already ran this week', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['notify_weekly_recap' => true]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-24', 'runs' => 1]);

    $this->artisan('streak:remind')->assertSuccessful();

    Queue::assertNotPushed(SendStreakReminderJob::class);
});

it('skips the demo user', function (): void {
    Queue::fake();

    $demo = User::factory()->demo()->create();
    TelegramConnection::factory()->for($demo)->create(['notify_weekly_recap' => true]);
    WeeklySnapshot::factory()->for($demo)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $this->artisan('streak:remind')
        ->expectsOutputToContain('Dispatched streak-at-risk reminder to 0 users.')
        ->assertSuccessful();

    Queue::assertNotPushed(SendStreakReminderJob::class);
});

it('skips a user with no live streak', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['notify_weekly_recap' => true]);
    // Last run two weeks back: a full week has closed since, so the streak is
    // already broken (consecutiveWeekStreak returns 0).
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10', 'runs' => 2]);

    $this->artisan('streak:remind')->assertSuccessful();

    Queue::assertNotPushed(SendStreakReminderJob::class);
});

it('does not double-send within the same at-risk week', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['notify_weekly_recap' => true]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $this->artisan('streak:remind')->assertSuccessful();
    $this->artisan('streak:remind')
        ->expectsOutputToContain('Dispatched streak-at-risk reminder to 0 users.')
        ->assertSuccessful();

    Queue::assertPushed(SendStreakReminderJob::class, 1);
});
