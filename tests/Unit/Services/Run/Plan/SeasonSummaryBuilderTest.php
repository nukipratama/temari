<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\SeasonSummaryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00'); // a Monday
    $this->builder = app(SeasonSummaryBuilder::class);
});
afterEach(fn () => Carbon::setTestNow());

it('covers every week from season start to season end for a self-scaled season', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create([
        'race_goal_id' => null,
        'starts_at' => '2026-08-10',
        'ends_at' => '2026-11-02', // 12 weeks later
    ]);

    $weeks = $this->builder->build($user, $season, Carbon::today());

    // week_start's diffInWeeks(starts_at, ends_at) + 1 convention (shared with
    // SeasonStreakSummaryBuilder::seasonPayload()'s "Week X of Y") counts the
    // boundary week on both ends, so a 12-week-later ends_at yields 13 weeks.
    expect($weeks)->toHaveCount(13)
        ->and($weeks[0]['week_start'])->toBe('2026-08-10')
        ->and($weeks[12]['week_start'])->toBe('2026-11-02');
});

it('cycles Build/Deload for a self-scaled season, never Base/Peak/Taper', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create([
        'race_goal_id' => null,
        'starts_at' => '2026-08-10',
        'ends_at' => '2026-11-02',
    ]);

    $phases = array_column($this->builder->build($user, $season, Carbon::today()), 'phase');

    expect($phases)->toBe(['build', 'build', 'build', 'deload', 'build', 'build', 'build', 'deload', 'build', 'build', 'build', 'deload', 'build'])
        ->and($phases)->not->toContain('base')
        ->and($phases)->not->toContain('peak')
        ->and($phases)->not->toContain('taper');
});

it('lays out a Base -> Build -> Peak -> Taper arc for a race-oriented season', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create([
        'race_date' => '2026-11-02', // 12 weeks from season start
        'distance_m' => 10_000,
    ]);
    $season = Season::factory()->for($user)->create([
        'race_goal_id' => $race->id,
        'starts_at' => '2026-08-10',
        'ends_at' => '2026-11-02',
    ]);

    $phases = array_column($this->builder->build($user, $season, Carbon::today()), 'phase');

    // Phases only ever move forward through the arc, never backward or repeat
    // after switching away: the first-occurrence order must be a subsequence
    // of the canonical base -> build -> peak -> taper arc.
    $firstOccurrenceOrder = array_values(array_unique($phases));
    $canonicalOrder = array_values(array_intersect(['base', 'build', 'peak', 'taper'], $firstOccurrenceOrder));

    expect($phases[0])->toBe('base')
        ->and($phases[count($phases) - 1])->toBe('taper')
        ->and($firstOccurrenceOrder)->toBe($canonicalOrder);
});

it('classifies weeks as history, current, or lookahead relative to today', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create([
        'race_goal_id' => null,
        'starts_at' => '2026-07-27', // 2 weeks before "today"
        'ends_at' => '2026-10-19',
    ]);

    $weeks = $this->builder->build($user, $season, Carbon::today());

    expect($weeks[0]['type'])->toBe('history')
        ->and($weeks[1]['type'])->toBe('history')
        ->and($weeks[2]['type'])->toBe('current') // week of 2026-08-10, "today"
        ->and($weeks[3]['type'])->toBe('lookahead');
});

it('carries a week\'s real distance_km from its WeeklySnapshot as actual_km', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create([
        'race_goal_id' => null,
        'starts_at' => '2026-08-10',
        'ends_at' => '2026-11-02',
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-08-16', // the first week's Sunday
        'distance_km' => 27.4,
    ]);

    $weeks = $this->builder->build($user, $season, Carbon::today());

    expect($weeks[0]['actual_km'])->toBe(27.4);
});

it('leaves actual_km null for a week with no WeeklySnapshot yet', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create([
        'race_goal_id' => null,
        'starts_at' => '2026-08-10',
        'ends_at' => '2026-11-02',
    ]);

    $weeks = $this->builder->build($user, $season, Carbon::today());

    expect($weeks[11]['actual_km'])->toBeNull();
});

it('gives a Deload week a lower planned_km than the Build week right before it', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 5, 'run_days' => null, 'long_run_day' => null]);
    $season = Season::factory()->for($user)->create([
        'race_goal_id' => null,
        'starts_at' => '2026-08-10',
        'ends_at' => '2026-11-02',
    ]);

    $weeks = $this->builder->build($user, $season, Carbon::today());

    expect($weeks[2]['phase'])->toBe('build')
        ->and($weeks[3]['phase'])->toBe('deload')
        ->and($weeks[3]['planned_km'])->toBeLessThan($weeks[2]['planned_km'])
        ->and($weeks[3]['planned_km'])->toBeGreaterThan(0.0);
});

it('never assigns Deload for a race-oriented season\'s planned volume', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create([
        'race_date' => '2026-11-02',
        'distance_m' => 10_000,
    ]);
    $season = Season::factory()->for($user)->create([
        'race_goal_id' => $race->id,
        'starts_at' => '2026-08-10',
        'ends_at' => '2026-11-02',
    ]);

    $phases = array_column($this->builder->build($user, $season, Carbon::today()), 'phase');

    expect($phases)->not->toContain(PlanPhase::Deload->value);
});
