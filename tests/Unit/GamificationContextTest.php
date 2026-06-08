<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Gamification\GamificationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * @param  array<string, int|float>  $detail
 */
function activityWithDetail(User $user, array $detail): void
{
    $activity = Activity::factory()->for($user)->create();
    $activity->detail()->create([
        'distance' => $detail['distance'] ?? 5000.0,
        'moving_time' => $detail['moving_time'] ?? 1800,
        'average_speed' => $detail['average_speed'] ?? 2.8,
    ]);
}

function weekSnapshot(User $user, string $weekEnding, int $runs): void
{
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => $weekEnding,
        'runs' => $runs,
    ]);
}

it('returns zero counts for a user with no data', function (): void {
    $user = User::factory()->create();

    $ctx = GamificationContext::forUser($user);

    expect($ctx->user)->toBe($user)
        ->and($ctx->prCount)->toBe(0)
        ->and($ctx->activityCount)->toBe(0)
        ->and($ctx->totalDistanceM)->toBe(0.0)
        ->and($ctx->totalDistanceKm())->toBe(0.0)
        ->and($ctx->streakWeeks)->toBe(0)
        ->and($ctx->twoWeekStreak)->toBe(0)
        ->and($ctx->tenKPlus)->toBe(0)
        ->and($ctx->fiveKPlus)->toBe(0)
        ->and($ctx->halfMarathon)->toBe(0)
        ->and($ctx->fastPace)->toBe(0)
        ->and($ctx->badgeCounts)->toBe([
            'anak_malam' => 0,
            'anak_pagi' => 0,
            'pejuang_hujan' => 0,
            'negative_split' => 0,
            'hari_panas' => 0,
            'z2_master' => 0,
        ]);
});

it('accumulates stats from activities and PRs', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->count(3)->sequence(
        ['category' => '5km'],
        ['category' => '10km'],
        ['category' => 'half_marathon'],
    )->create();
    Activity::factory()->for($user)->count(5)->create();

    $ctx = GamificationContext::forUser($user);

    expect($ctx->prCount)->toBe(3)
        ->and($ctx->activityCount)->toBe(5);
});

it('converts totalDistanceM to km', function (): void {
    $user = User::factory()->create();

    // Create an activity with a detail that has distance.
    $activity = Activity::factory()->for($user)->create();
    $activity->detail()->create([
        'distance' => 15000.0,
        'moving_time' => 3600,
    ]);

    $ctx = GamificationContext::forUser($user);

    expect($ctx->totalDistanceM)->toBe(15000.0)
        ->and($ctx->totalDistanceKm())->toBe(15.0);
});

it('counts only consecutive weeks for the streak, breaking at the first gap', function (): void {
    $user = User::factory()->create();
    // 4 weeks logged but week_ending dates are NOT adjacent: 1, 5, 9, 13.
    // Before the fix this returned 4; the streak must be 1 (only the most
    // recent week has no adjacent predecessor).
    weekSnapshot($user, '2026-01-04', 2);
    weekSnapshot($user, '2026-02-01', 2);
    weekSnapshot($user, '2026-03-01', 2);
    weekSnapshot($user, '2026-03-29', 2);

    $ctx = GamificationContext::forUser($user);

    expect($ctx->streakWeeks)->toBe(1)
        ->and($ctx->twoWeekStreak)->toBe(1);
});

it('counts a run of adjacent weeks as a full streak', function (): void {
    $user = User::factory()->create();
    // 4 consecutive Sundays, exactly 7 days apart.
    $base = Carbon::parse('2026-05-31');
    foreach ([0, 1, 2, 3] as $w) {
        weekSnapshot($user, $base->copy()->subWeeks($w)->toDateString(), 2);
    }

    $ctx = GamificationContext::forUser($user);

    expect($ctx->streakWeeks)->toBe(4)
        ->and($ctx->twoWeekStreak)->toBe(2);
});

it('breaks the streak at the first gap even when later weeks are adjacent', function (): void {
    $user = User::factory()->create();
    // Most recent two weeks adjacent, then a one-week gap, then two more.
    weekSnapshot($user, '2026-05-31', 2);
    weekSnapshot($user, '2026-05-24', 2);
    // gap: 2026-05-17 missing
    weekSnapshot($user, '2026-05-10', 2);
    weekSnapshot($user, '2026-05-03', 2);

    $ctx = GamificationContext::forUser($user);

    expect($ctx->streakWeeks)->toBe(2);
});

it('ignores weeks with zero runs when computing the streak', function (): void {
    $user = User::factory()->create();
    // The most recent adjacent week has runs=0, so it is filtered out and the
    // streak walks the remaining run-bearing weeks (which now have a gap).
    weekSnapshot($user, '2026-05-31', 0);
    weekSnapshot($user, '2026-05-24', 2);
    weekSnapshot($user, '2026-05-17', 2);

    $ctx = GamificationContext::forUser($user);

    expect($ctx->streakWeeks)->toBe(2);
});

it('returns zero streak when no week has runs', function (): void {
    $user = User::factory()->create();
    weekSnapshot($user, '2026-05-31', 0);

    $ctx = GamificationContext::forUser($user);

    expect($ctx->streakWeeks)->toBe(0)
        ->and($ctx->twoWeekStreak)->toBe(0);
});

it('counts a half-marathon run using the 21,097.5 m constant, not 21,000', function (): void {
    $user = User::factory()->create();
    // 21,050 m clears the old 21,000 literal but is short of the real HM
    // distance, so it must NOT count.
    activityWithDetail($user, ['distance' => 21050.0]);
    activityWithDetail($user, ['distance' => 21097.5]);

    $ctx = GamificationContext::forUser($user);

    expect($ctx->halfMarathon)->toBe(1);
});

it('counts a sub-5:30/km run as fastPace but not a 5:33/km run', function (): void {
    $user = User::factory()->create();
    // 1000/330 m/s == 5:30/km exactly (qualifies, >=). 3.0 m/s == 5:33/km (no).
    activityWithDetail($user, ['average_speed' => 1000 / 330]);
    activityWithDetail($user, ['average_speed' => 3.0]);

    $ctx = GamificationContext::forUser($user);

    expect($ctx->fastPace)->toBe(1);
});
