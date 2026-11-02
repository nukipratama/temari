<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\InboxNotification;
use App\Models\PlannedSession;
use App\Models\User;
use App\Notifications\EasyTooHardNotification;
use App\Services\Run\Plan\TooHardWarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

const WARNING_MONDAY = '2026-11-02';

function warningEasyDay(User $user, string $date, int $overCapSec): void
{
    PlannedSession::factory()->for($user)->create(['date' => $date, 'phase' => PlanPhase::Build, 'session_type' => SessionType::Easy]);
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::parse($date.' 06:00:00'),
        'moving_time' => 3000,
        'stream_summary' => ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => $overCapSec],
    ]);
}

function warnOn(User $user, string $runDate, string $today): void
{
    app(TooHardWarning::class)->warn($user, Carbon::parse($runDate), Carbon::parse($today));
}

beforeEach(function (): void {
    Notification::fake();
});

it('warns once the week is one hot easy day short of costing next week a quality session', function (): void {
    $user = User::factory()->create();
    warningEasyDay($user, WARNING_MONDAY, 800);

    warnOn($user, WARNING_MONDAY, WARNING_MONDAY);

    Notification::assertSentTo($user, EasyTooHardNotification::class, static fn (EasyTooHardNotification $n): bool => $n->state === EasyTooHardNotification::ONE_MORE && $n->weekStart === WARNING_MONDAY);
});

it('says next week loses a quality session once a second day runs hot', function (): void {
    $user = User::factory()->create();
    warningEasyDay($user, WARNING_MONDAY, 800);
    warningEasyDay($user, '2026-11-04', 800);

    warnOn($user, '2026-11-04', '2026-11-04');

    Notification::assertSentTo($user, EasyTooHardNotification::class, static fn (EasyTooHardNotification $n): bool => $n->state === EasyTooHardNotification::SET && ! $n->egregious);
});

it('says so at once when one easy day runs far enough over to set it alone', function (): void {
    $user = User::factory()->create();
    warningEasyDay($user, WARNING_MONDAY, 2_400);

    warnOn($user, WARNING_MONDAY, WARNING_MONDAY);

    Notification::assertSentTo($user, EasyTooHardNotification::class, static fn (EasyTooHardNotification $n): bool => $n->state === EasyTooHardNotification::SET && $n->egregious);
});

it('sends each state once a week', function (): void {
    $user = User::factory()->create();
    warningEasyDay($user, WARNING_MONDAY, 800);
    InboxNotification::factory()->for($user)->create(['dedupe_key' => 'easy_too_hard:'.WARNING_MONDAY.':one_more']);

    warnOn($user, WARNING_MONDAY, WARNING_MONDAY);

    Notification::assertNothingSent();
});

it('stays quiet for an easy day held under the cap, a run from an earlier week, and the demo athlete', function (string $case): void {
    $user = User::factory()->create(['is_demo' => $case === 'demo']);
    warningEasyDay($user, $case === 'earlier week' ? '2026-10-28' : WARNING_MONDAY, $case === 'under the cap' ? 300 : 800);

    warnOn($user, $case === 'earlier week' ? '2026-10-28' : WARNING_MONDAY, WARNING_MONDAY);

    Notification::assertNothingSent();
})->with(['under the cap', 'earlier week', 'demo']);
