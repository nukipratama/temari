<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\User;
use App\Notifications\PlanChangedNotification;
use App\Services\Run\Plan\PlanChangeNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

const NOTICE_TODAY = '2026-11-03';

beforeEach(function (): void {
    Carbon::setTestNow(NOTICE_TODAY.' 08:00:00');
    Notification::fake();
});
afterEach(fn () => Carbon::setTestNow());

function noticeWeek(User $user): void
{
    PlanAdaptation::factory()->for($user)->create(['week_start' => '2026-11-02']);
}

function noticeTempo(User $user, string $date = '2026-11-05'): PlannedSession
{
    return PlannedSession::factory()->for($user)->create([
        'date' => $date,
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Tempo,
        'prescribed_hard_minutes' => 20,
        'prescribed_pace_band' => PaceBand::Threshold,
    ]);
}

function noticeRebuild(User $user, Closure $change): void
{
    $notice = app(PlanChangeNotice::class);
    $baseline = $notice->baseline($user, Carbon::today());
    $change();
    $notice->announce($user, $baseline, Carbon::today());
}

it('announces a session whose hard minutes a rebuild changed, after a settling delay', function (): void {
    $user = User::factory()->create();
    noticeWeek($user);
    $tempo = noticeTempo($user);

    noticeRebuild($user, fn () => $tempo->update(['prescribed_hard_minutes' => 0, 'prescribed_pace_band' => null]));

    Notification::assertSentTo($user, PlanChangedNotification::class, static fn (PlanChangedNotification $n): bool => $n->date === '2026-11-05'
        && $n->was === ['session_type' => 'tempo', 'hard_minutes' => 20, 'pace_band' => 'threshold']
        && $n->delay instanceof DateTimeInterface && Carbon::instance($n->delay)->eq(now()->addMinutes(PlanChangeNotice::SETTLE_MINUTES)));
});

it('announces each session once, however often it changes again', function (): void {
    $user = User::factory()->create();
    noticeWeek($user);
    $tempo = noticeTempo($user);

    noticeRebuild($user, fn () => $tempo->update(['prescribed_hard_minutes' => 15]));
    noticeRebuild($user, fn () => $tempo->update(['prescribed_hard_minutes' => 0]));

    Notification::assertSentToTimes($user, PlanChangedNotification::class, 1);
});

it('says nothing for a rebuild that leaves every decision as it was', function (): void {
    $user = User::factory()->create();
    noticeWeek($user);
    $tempo = noticeTempo($user);

    noticeRebuild($user, fn () => $tempo->update([
        'prescribed_pace_sec_per_km' => 270,
        'clamped_km' => 6.0,
        'readiness_assessment' => ['ceiling' => 'easy_only', 'reasons' => ['hours_since_hard' => 20]],
    ]));

    Notification::assertNothingSent();
});

it('never announces to the demo athlete', function (): void {
    $user = User::factory()->demo()->create();
    noticeWeek($user);
    $tempo = noticeTempo($user);

    noticeRebuild($user, fn () => $tempo->update(['prescribed_hard_minutes' => 0]));

    Notification::assertNothingSent();
});

it('treats the week\'s first build as writing the week, not changing it', function (): void {
    $user = User::factory()->create();
    $tempo = noticeTempo($user);

    noticeRebuild($user, fn () => $tempo->update(['prescribed_hard_minutes' => 0]));

    Notification::assertNothingSent();
});

it('leaves days already past and next week\'s projection unannounced', function (string $date): void {
    $user = User::factory()->create();
    noticeWeek($user);
    $tempo = noticeTempo($user, $date);

    noticeRebuild($user, fn () => $tempo->update(['prescribed_hard_minutes' => 0]));

    Notification::assertNothingSent();
})->with(['yesterday' => '2026-11-02', 'next week' => '2026-11-10']);

it('announces a session whose type a rebuild replaced', function (): void {
    $user = User::factory()->create();
    noticeWeek($user);
    $tempo = noticeTempo($user);
    $date = $tempo->date->toDateString();

    noticeRebuild($user, function () use ($tempo, $user, $date): void {
        $tempo->delete();
        PlannedSession::factory()->for($user)->create(['date' => $date, 'phase' => PlanPhase::Build, 'session_type' => SessionType::Easy]);
    });

    Notification::assertSentToTimes($user, PlanChangedNotification::class, 1);
});
