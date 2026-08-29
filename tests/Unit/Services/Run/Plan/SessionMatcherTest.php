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
    expect(SessionMatcher::scoreFor(10.0, 0.0, false, false))->toBe([
        'status' => PlannedSessionStatus::Planned,
        'score' => null,
        'ran_anyway' => false,
    ]);
});

it('skip wins over everything, even if the athlete happened to run', function (): void {
    expect(SessionMatcher::scoreFor(10.0, 10.0, true, true))->toBe([
        'status' => PlannedSessionStatus::Skip,
        'score' => null,
        'ran_anyway' => false,
    ])
        ->and(SessionMatcher::scoreFor(0.0, 5.0, true, true)['status'])->toBe(PlannedSessionStatus::Skip);
});

it('counts a rest day as done, since it asked for nothing', function (): void {
    $result = SessionMatcher::scoreFor(0.0, 0.0, true, false);

    expect($result['status'])->toBe(PlannedSessionStatus::Done)
        ->and($result['score'])->toBeNull()
        ->and($result['ran_anyway'])->toBeFalse();
});

it('flags ran_anyway on a rest day with any activity logged, without changing the Done status', function (): void {
    $result = SessionMatcher::scoreFor(0.0, 3.5, true, false);

    expect($result['status'])->toBe(PlannedSessionStatus::Done)
        ->and($result['ran_anyway'])->toBeTrue();
});

it('grades a past session on a continuous km-ratio score, banded into a status', function (): void {
    expect(SessionMatcher::scoreFor(10.0, 10.0, true, false))->toBe(['status' => PlannedSessionStatus::Done, 'score' => 100, 'ran_anyway' => false])
        ->and(SessionMatcher::scoreFor(10.0, 10.0 * SessionMatcher::DONE_FRACTION, true, false)['status'])->toBe(PlannedSessionStatus::Done)
        ->and(SessionMatcher::scoreFor(10.0, 5.0, true, false))->toBe(['status' => PlannedSessionStatus::Partial, 'score' => 50, 'ran_anyway' => false])
        ->and(SessionMatcher::scoreFor(10.0, 10.0 * SessionMatcher::PARTIAL_FRACTION, true, false)['status'])->toBe(PlannedSessionStatus::Partial)
        ->and(SessionMatcher::scoreFor(10.0, 1.0, true, false))->toBe(['status' => PlannedSessionStatus::Missed, 'score' => 10, 'ran_anyway' => false])
        ->and(SessionMatcher::scoreFor(10.0, 0.0, true, false)['status'])->toBe(PlannedSessionStatus::Missed);
});

it('flags Overreached at and above the overreached fraction', function (): void {
    expect(SessionMatcher::scoreFor(10.0, 10.0 * SessionMatcher::OVERREACHED_FRACTION, true, false))
        ->toBe(['status' => PlannedSessionStatus::Overreached, 'score' => 130, 'ran_anyway' => false])
        ->and(SessionMatcher::scoreFor(10.0, 12.9, true, false)['status'])->toBe(PlannedSessionStatus::Done)
        ->and(SessionMatcher::scoreFor(10.0, 20.0, true, false))->toBe(['status' => PlannedSessionStatus::Overreached, 'score' => 200, 'ran_anyway' => false]);
});

it('returns no statuses for an empty plan', function (): void {
    $matcher = app(SessionMatcher::class);

    expect($matcher->statuses(User::factory()->create(), [], [], Carbon::parse('2026-08-10')))->toBe([]);
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
    ], [], Carbon::parse('2026-08-10'));

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

    $statuses = app(SessionMatcher::class)->statuses($user, ['2026-08-03' => 10.0], [], Carbon::parse('2026-08-10'));

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Done);
});

it('ignores another athlete\'s runs', function (): void {
    $user = User::factory()->create();
    logRun(User::factory()->create(), '2026-08-03', 10.0);

    $statuses = app(SessionMatcher::class)->statuses($user, ['2026-08-03' => 10.0], [], Carbon::parse('2026-08-10'));

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Missed);
});

it('resolves a skipped day to Skip regardless of what was logged', function (): void {
    $user = User::factory()->create();
    logRun($user, '2026-08-03', 10.0);

    $statuses = app(SessionMatcher::class)->statuses(
        $user,
        ['2026-08-03' => 10.0],
        ['2026-08-03' => true],
        Carbon::parse('2026-08-10'),
    );

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Skip);
});

it('scoreRange returns the full verdict per day, not just the status', function (): void {
    $user = User::factory()->create();
    logRun($user, '2026-08-03', 10.0);

    $results = app(SessionMatcher::class)->scoreRange(
        $user,
        ['2026-08-03' => 10.0, '2026-08-04' => 0.0],
        [],
        Carbon::parse('2026-08-10'),
    );

    expect($results['2026-08-03'])->toBe(['status' => PlannedSessionStatus::Done, 'score' => 100, 'ran_anyway' => false])
        ->and($results['2026-08-04'])->toBe(['status' => PlannedSessionStatus::Done, 'score' => null, 'ran_anyway' => false]);
});
