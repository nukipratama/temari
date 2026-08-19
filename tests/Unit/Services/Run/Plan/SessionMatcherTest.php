<?php

declare(strict_types=1);

use App\Enums\PlannedSessionStatus;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Plan\SessionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function logRun(User $user, string $date, float $km): void
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->create([
        'activity_id' => $activity->id,
        'start_date_local' => Carbon::parse($date.' 06:00:00'),
        'distance' => $km * 1000,
    ]);
}

it('leaves a date that has not happened yet as planned', function (): void {
    expect(SessionMatcher::statusFor(10.0, 0.0, false))->toBe(PlannedSessionStatus::Planned);
});

it('counts a rest day as done, since it asked for nothing', function (): void {
    expect(SessionMatcher::statusFor(0.0, 0.0, true))->toBe(PlannedSessionStatus::Done);
});

it('grades a past session on km run against km prescribed', function (): void {
    expect(SessionMatcher::statusFor(10.0, 10.0, true))->toBe(PlannedSessionStatus::Done)
        ->and(SessionMatcher::statusFor(10.0, 10.0 * SessionMatcher::DONE_FRACTION, true))->toBe(PlannedSessionStatus::Done)
        ->and(SessionMatcher::statusFor(10.0, 5.0, true))->toBe(PlannedSessionStatus::Partial)
        ->and(SessionMatcher::statusFor(10.0, 10.0 * SessionMatcher::PARTIAL_FRACTION, true))->toBe(PlannedSessionStatus::Partial)
        ->and(SessionMatcher::statusFor(10.0, 1.0, true))->toBe(PlannedSessionStatus::Missed)
        ->and(SessionMatcher::statusFor(10.0, 0.0, true))->toBe(PlannedSessionStatus::Missed);
});

it('returns no statuses for an empty plan', function (): void {
    $matcher = app(SessionMatcher::class);

    expect($matcher->statuses(User::factory()->create(), [], Carbon::parse('2026-08-10')))->toBe([]);
});

it('matches each logged run to the day the plan asked for it', function (): void {
    $user = User::factory()->create();
    logRun($user, '2026-08-03', 10.0);
    logRun($user, '2026-08-04', 4.0);

    $statuses = app(SessionMatcher::class)->statuses($user, [
        '2026-08-03' => 10.0,
        '2026-08-04' => 10.0,
        '2026-08-05' => 10.0,
        '2026-08-10' => 10.0,
    ], Carbon::parse('2026-08-10'));

    expect($statuses)->toBe([
        '2026-08-03' => PlannedSessionStatus::Done,
        '2026-08-04' => PlannedSessionStatus::Partial,
        '2026-08-05' => PlannedSessionStatus::Missed,
        '2026-08-10' => PlannedSessionStatus::Planned,
    ]);
});

it('sums two runs on the same day before grading it', function (): void {
    $user = User::factory()->create();
    logRun($user, '2026-08-03', 6.0);
    logRun($user, '2026-08-03', 5.0);

    $statuses = app(SessionMatcher::class)->statuses($user, ['2026-08-03' => 10.0], Carbon::parse('2026-08-10'));

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Done);
});

it('ignores another athlete\'s runs', function (): void {
    $user = User::factory()->create();
    logRun(User::factory()->create(), '2026-08-03', 10.0);

    $statuses = app(SessionMatcher::class)->statuses($user, ['2026-08-03' => 10.0], Carbon::parse('2026-08-10'));

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Missed);
});

it('scores a week on its completed share of elapsed sessions, rest days excluded', function (): void {
    $user = User::factory()->create();
    logRun($user, '2026-08-03', 10.0);
    logRun($user, '2026-08-05', 4.0);

    $adherence = app(SessionMatcher::class)->weekAdherence($user, [
        '2026-08-03' => 10.0,
        '2026-08-04' => 0.0,
        '2026-08-05' => 10.0,
        '2026-08-06' => 10.0,
    ], Carbon::parse('2026-08-10'));

    expect($adherence)->toBe([
        'planned_km' => 30.0,
        'completed_km' => 14.0,
        'planned_sessions' => 3,
        'completed_sessions' => 2,
        'adherence' => 0.667,
    ]);
});

it('scores a fully missed week at zero adherence', function (): void {
    $user = User::factory()->create();

    $adherence = app(SessionMatcher::class)->weekAdherence($user, [
        '2026-08-03' => 10.0,
        '2026-08-05' => 8.0,
    ], Carbon::parse('2026-08-10'));

    expect($adherence['adherence'])->toBe(0.0)
        ->and($adherence['completed_sessions'])->toBe(0);
});

it('treats a week with nothing yet elapsed as fully adhered, not as a miss', function (): void {
    $user = User::factory()->create();

    $adherence = app(SessionMatcher::class)->weekAdherence($user, [
        '2026-08-12' => 10.0,
    ], Carbon::parse('2026-08-10'));

    expect($adherence['adherence'])->toBe(1.0)
        ->and($adherence['planned_sessions'])->toBe(0);
});

it('treats an empty week as fully adhered', function (): void {
    $adherence = app(SessionMatcher::class)->weekAdherence(User::factory()->create(), [], Carbon::parse('2026-08-10'));

    expect($adherence['adherence'])->toBe(1.0)
        ->and($adherence['planned_km'])->toBe(0.0);
});
