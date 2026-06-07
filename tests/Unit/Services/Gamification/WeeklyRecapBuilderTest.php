<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Services\Gamification\GoalResolver;
use App\Services\Gamification\WeeklyRecapBuilder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    // A fixed Wednesday so "this week" = Mon 2026-05-11 .. Sun 2026-05-17.
    Carbon::setTestNow('2026-05-13 09:00:00');
    $this->builder = app(WeeklyRecapBuilder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{0: Activity, 1: ActivityDetail}
 */
function recapRun(User $user, string $startLocal, Rarity $rarity, string $move = 'Langkah Mantap'): array
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $startLocal,
        'distance' => 8000,
        'summary_polyline' => '_p~iF~ps|U',
    ]);
    RunCard::factory()->for($activity)->create([
        'rarity' => $rarity,
        'special_move' => $move,
    ]);

    return [$activity, $detail];
}

it('reports the current-week range as Monday through Sunday', function (): void {
    $user = User::factory()->create();

    $recap = $this->builder->forUser($user);

    expect($recap->weekStart)->toBe('2026-05-11')
        ->and($recap->weekEnd)->toBe('2026-05-17');
});

it('returns a zeroed recap when there are no runs this week', function (): void {
    $user = User::factory()->create();

    $recap = $this->builder->forUser($user);

    expect($recap->thisWeekKm)->toBe(0.0)
        ->and($recap->thisWeekRuns)->toBe(0)
        ->and($recap->deltaPct)->toBeNull()
        ->and($recap->streakWeeks)->toBe(0)
        ->and($recap->bestCard)->toBeNull();
});

it('reads km + runs from this week and computes the delta vs last week', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-10', // last week (Sunday).
        'distance_km' => 20.0,
        'runs' => 3,
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17', // this week (Sunday).
        'distance_km' => 25.0,
        'runs' => 4,
    ]);

    $recap = $this->builder->forUser($user);

    // (25 - 20) / 20 = +25%.
    expect($recap->thisWeekKm)->toBe(25.0)
        ->and($recap->thisWeekRuns)->toBe(4)
        ->and($recap->lastWeekKm)->toBe(20.0)
        ->and($recap->deltaPct)->toBe(25);
});

it('returns a null delta when there is no last-week snapshot (first week)', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17',
        'distance_km' => 18.0,
        'runs' => 3,
    ]);

    $recap = $this->builder->forUser($user);

    expect($recap->thisWeekKm)->toBe(18.0)
        ->and($recap->deltaPct)->toBeNull();
});

it('guards divide-by-zero when last week had zero km', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-10',
        'distance_km' => 0.0,
        'runs' => 0,
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17',
        'distance_km' => 12.0,
        'runs' => 2,
    ]);

    $recap = $this->builder->forUser($user);

    expect($recap->deltaPct)->toBeNull();
});

it('reports a negative delta when km dropped vs last week', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-10',
        'distance_km' => 40.0,
        'runs' => 5,
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17',
        'distance_km' => 30.0,
        'runs' => 4,
    ]);

    $recap = $this->builder->forUser($user);

    // (30 - 40) / 40 = -25%.
    expect($recap->deltaPct)->toBe(-25);
});

it('counts a consecutive-week streak of adjacent run weeks', function (): void {
    $user = User::factory()->create();
    foreach (['2026-04-26', '2026-05-03', '2026-05-10', '2026-05-17'] as $weekEnding) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => $weekEnding,
            'distance_km' => 20.0,
            'runs' => 3,
        ]);
    }

    $recap = $this->builder->forUser($user);

    expect($recap->streakWeeks)->toBe(4);
});

it('breaks the streak at a missing (gap) week', function (): void {
    $user = User::factory()->create();
    // 05-17 and 05-10 are adjacent; then a gap (no 05-03); then 04-26.
    foreach (['2026-04-26', '2026-05-10', '2026-05-17'] as $weekEnding) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => $weekEnding,
            'distance_km' => 20.0,
            'runs' => 3,
        ]);
    }

    $recap = $this->builder->forUser($user);

    expect($recap->streakWeeks)->toBe(2);
});

it('ignores zero-run weeks when counting the streak', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17',
        'distance_km' => 20.0,
        'runs' => 2,
    ]);
    // The intervening week exists but has no runs, so it breaks adjacency.
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-10',
        'distance_km' => 0.0,
        'runs' => 0,
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-03',
        'distance_km' => 15.0,
        'runs' => 2,
    ]);

    $recap = $this->builder->forUser($user);

    expect($recap->streakWeeks)->toBe(1);
});

it('picks the highest-rarity card among this week as the best card', function (): void {
    $user = User::factory()->create();
    recapRun($user, '2026-05-12 06:00:00', Rarity::Common, 'Langkah Mantap');
    recapRun($user, '2026-05-14 06:00:00', Rarity::Epic, 'Pemburu Sabar');
    recapRun($user, '2026-05-15 06:00:00', Rarity::Rare, 'Metronom');

    $recap = $this->builder->forUser($user);

    expect($recap->bestCard)->not->toBeNull()
        ->and($recap->bestCard['rarity'])->toBe('epic')
        ->and($recap->bestCard['special_move'])->toBe('Pemburu Sabar')
        ->and($recap->bestCard['distance_km'])->toBe(8.0)
        ->and($recap->bestCard['polyline'])->toBe('_p~iF~ps|U');
});

it('breaks a best-card rarity tie toward the most recent run', function (): void {
    $user = User::factory()->create();
    recapRun($user, '2026-05-12 06:00:00', Rarity::Rare, 'Yang Lama');
    recapRun($user, '2026-05-16 06:00:00', Rarity::Rare, 'Yang Baru');

    $recap = $this->builder->forUser($user);

    expect($recap->bestCard['special_move'])->toBe('Yang Baru');
});

it('excludes cards from runs outside the current week', function (): void {
    $user = User::factory()->create();
    // Last week's run — must not be picked.
    recapRun($user, '2026-05-08 06:00:00', Rarity::Legendary, 'Minggu Lalu');
    recapRun($user, '2026-05-13 06:00:00', Rarity::Common, 'Minggu Ini');

    $recap = $this->builder->forUser($user);

    expect($recap->bestCard['special_move'])->toBe('Minggu Ini')
        ->and($recap->bestCard['rarity'])->toBe('common');
});

it('returns a null best card when there are no runs this week', function (): void {
    $user = User::factory()->create();
    recapRun($user, '2026-05-08 06:00:00', Rarity::Epic, 'Minggu Lalu');

    $recap = $this->builder->forUser($user);

    expect($recap->bestCard)->toBeNull();
});

it('carries the run mood onto the best card when a post-run story line exists', function (): void {
    $user = User::factory()->create();
    [$activity] = recapRun($user, '2026-05-13 06:00:00', Rarity::Epic, 'Pemburu Sabar');
    StoryLine::factory()->for($user)->create([
        'activity_id' => $activity->id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => 'nyala',
    ]);

    $recap = $this->builder->forUser($user);

    expect($recap->bestCard['mood'])->toBe('nyala');
});

it('surfaces the nearest incomplete goal with a remainder label', function (): void {
    $user = User::factory()->create();
    // A brand-new user: the "catat lari pertama" goal sits at 0/1, so it is the
    // nearest incomplete goal and needs 1 more.
    $recap = $this->builder->forUser($user);

    expect($recap->nearestGoal)->not->toBeNull()
        ->and($recap->nearestGoal['target'])->toBeGreaterThan(0)
        ->and($recap->nearestGoal['ratio'])->toBeGreaterThanOrEqual(0.0)
        ->and($recap->nearestGoal['ratio'])->toBeLessThanOrEqual(1.0)
        ->and($recap->nearestGoal['remainder_label'])->toContain('lagi');
});

it('returns a null nearest goal when every goal is already complete', function (): void {
    $user = User::factory()->create();
    // A goal is "complete" when its unlock_key is in the user's UserUnlock rows.
    // Unlock every catalog key so GoalResolver marks every goal completed, which
    // leaves closestToCompletion with no incomplete goal to surface.
    foreach (app(GoalResolver::class)->forUser($user) as $goal) {
        UserUnlock::query()->create([
            'user_id' => $user->id,
            'unlock_key' => $goal['id'],
            'unlocked_at' => now(),
        ]);
    }

    expect($this->builder->forUser($user)->nearestGoal)->toBeNull();
});
