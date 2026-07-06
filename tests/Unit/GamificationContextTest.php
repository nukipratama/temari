<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
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
            'lawan_angin' => 0,
        ]);
});

it('accumulates stats from activities and PRs', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->count(3)->sequence(
        ['category' => '5km'],
        ['category' => '10km'],
        ['category' => 'half_marathon'],
    )->create();
    Activity::factory()->for($user)->analyzed()->count(5)->create();

    $ctx = GamificationContext::forUser($user);

    expect($ctx->prCount)->toBe(3)
        ->and($ctx->activityCount)->toBe(5);
});

it('counts only ingested runs, ignoring un-analyzed stubs', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->analyzed()->count(3)->create();
    // A stub still in the sync backlog (no analyzed_at) has no data and must not
    // advance run-count goals/unlocks (1 / 10 / 50 runs).
    Activity::factory()->for($user)->create(['analyzed_at' => null]);

    expect(GamificationContext::forUser($user)->activityCount)->toBe(3);
});

it('counts run cards by rarity across the user\'s activities', function (): void {
    $user = User::factory()->create();
    $a = Activity::factory()->for($user)->analyzed()->create();
    $b = Activity::factory()->for($user)->analyzed()->create();
    $c = Activity::factory()->for($user)->analyzed()->create();
    RunCard::factory()->for($a)->create(['rarity' => 'rare']);
    RunCard::factory()->for($b)->create(['rarity' => 'rare']);
    RunCard::factory()->for($c)->create(['rarity' => 'legendary']);

    $ctx = GamificationContext::forUser($user);

    // toEqual (not toBe): the underlying query is a GROUP BY with no ORDER BY,
    // so MySQL doesn't guarantee key order — only the rarity=>count mapping
    // itself is part of the contract.
    expect($ctx->rarityCounts)->toEqual(['rare' => 2, 'legendary' => 1]);
});

it('counts runs at exactly the 10km and 5km thresholds as qualifying', function (): void {
    $user = User::factory()->create();
    activityWithDetail($user, ['distance' => 10000.0]);
    activityWithDetail($user, ['distance' => 9999.0]);
    activityWithDetail($user, ['distance' => 5000.0]);
    activityWithDetail($user, ['distance' => 4999.0]);

    $ctx = GamificationContext::forUser($user);

    // 10000 clears both thresholds (>=10000 and >=5000); 9999 and 5000 each
    // clear only the 5km one; 4999 clears neither.
    expect($ctx->tenKPlus)->toBe(1)
        ->and($ctx->fiveKPlus)->toBe(3);
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

it('passes the streak from WeeklySnapshot::consecutiveWeekStreak through, capping twoWeekStreak at 2', function (): void {
    // The streak *computation* itself (gap-breaking, zero-run weeks, staleness)
    // is WeeklySnapshot::consecutiveWeekStreak()'s own responsibility and is
    // fully covered by WeeklySnapshotTest; GamificationContext only forwards
    // streakWeeks and derives twoWeekStreak = min(streakWeeks, 2) from it, so
    // this just proves that wiring with a simple adjacent-weeks case.
    Carbon::setTestNow('2026-06-02');
    $user = User::factory()->create();
    // 4 consecutive Sundays, exactly 7 days apart.
    $base = Carbon::parse('2026-05-31');
    foreach ([0, 1, 2, 3] as $w) {
        weekSnapshot($user, $base->copy()->subWeeks($w)->toDateString(), 2);
    }

    $ctx = GamificationContext::forUser($user);

    expect($ctx->streakWeeks)->toBe(4)
        ->and($ctx->twoWeekStreak)->toBe(2);

    Carbon::setTestNow();
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
