<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\NotificationPreference;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\User;
use App\Notifications\EasyTooHardNotification;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    Carbon::setTestNow('2026-11-08 19:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/** @param array{date: string, time_trial: bool, session_type: string}|null $drop */
function lockedWeek(User $user, ?array $drop = ['date' => '2026-11-12', 'time_trial' => false, 'session_type' => 'tempo']): void
{
    PlanAdaptation::factory()->for($user)->create(['week_start' => '2026-11-02', 'next_week_drop' => $drop]);
    foreach (['2026-11-03', '2026-11-05'] as $date) {
        PlannedSession::factory()->for($user)->create(['date' => $date, 'phase' => PlanPhase::Build, 'session_type' => SessionType::Easy]);
        ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
            'start_date_local' => Carbon::parse($date.' 06:00:00'),
            'moving_time' => 3000,
            'stream_summary' => ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 800],
        ]);
    }
}

it('tells each athlete whose hot week costs next week a session which one, once', function (): void {
    $user = User::factory()->create();
    lockedWeek($user);

    $this->artisan('plan:lock-next-week')->expectsOutputToContain('Told 1 athletes')->assertSuccessful();
    $this->artisan('plan:lock-next-week')->expectsOutputToContain('Told 0 athletes')->assertSuccessful();

    Notification::assertSentToTimes($user, EasyTooHardNotification::class, 1);
    Notification::assertSentTo($user, EasyTooHardNotification::class, static fn (EasyTooHardNotification $n): bool => $n->state === EasyTooHardNotification::LOCKED);
});

it('skips the demo athlete, an athlete who turned notifications off, and a week with nothing next week to lose', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);
    lockedWeek($demo);
    $muted = User::factory()->create();
    NotificationPreference::factory()->for($muted)->create(['notifications_enabled' => false]);
    lockedWeek($muted);
    $nothing = User::factory()->create();
    lockedWeek($nothing, null);

    $this->artisan('plan:lock-next-week')->expectsOutputToContain('Told 0 athletes')->assertSuccessful();

    Notification::assertNothingSent();
});

it('runs on sunday evening', function (): void {
    $event = nonNull(collect(app(Schedule::class)->events())->first(fn (Event $e): bool => str_ends_with((string) $e->command, 'plan:lock-next-week')));

    expect($event->expression)->toBe('0 19 * * 0');
});
