<?php

declare(strict_types=1);

use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\SeasonGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function ctlGoalFor(Season $season): SeasonGoal
{
    return SeasonGoal::query()->create([
        'season_id' => $season->id, 'title' => 'Grow your fitness (CTL) this season', 'metric' => 'season_ctl_growth',
        'metric_key' => null, 'target' => 3.0, 'unit' => 'CTL pts',
    ]);
}

it('replaces an open goal-less season\'s CTL goal with the consistency goal and leaves settled history alone', function (): void {
    Carbon::setTestNow('2026-10-05 08:00:00');
    $user = User::factory()->create();
    $open = Season::factory()->for($user)->create(['starts_at' => '2026-09-07', 'ends_at' => '2026-11-30']);
    $settled = Season::factory()->for($user)->create(['starts_at' => '2026-06-08', 'ends_at' => '2026-08-31']);
    $race = Season::factory()->for($user)->create([
        'race_goal_id' => RaceGoal::factory()->for($user)->create()->id, 'starts_at' => '2026-09-01', 'ends_at' => '2026-12-06',
    ]);
    $openGoal = ctlGoalFor($open);
    $settledGoal = ctlGoalFor($settled);
    $raceGoal = ctlGoalFor($race);

    $migration = require base_path('database/migrations/2026_10_02_000200_replace_open_ctl_growth_goals_with_consistency.php');
    $migration->up();
    $migration->up();

    expect($openGoal->fresh()->metric)->toBe('season_consistent_weeks')
        ->and($openGoal->fresh()->target)->toBe(12.0)
        ->and($openGoal->fresh()->unit)->toBe('weeks')
        ->and($settledGoal->fresh()->metric)->toBe('season_ctl_growth')
        ->and($raceGoal->fresh()->metric)->toBe('season_ctl_growth');
});

it('turns every consistency goal back into a CTL goal on rollback, so reverted code can read it', function (): void {
    Carbon::setTestNow('2026-10-05 08:00:00');
    $season = Season::factory()->for(User::factory()->create())->create(['starts_at' => '2026-09-07', 'ends_at' => '2026-11-30']);
    $goal = ctlGoalFor($season);
    $migration = require base_path('database/migrations/2026_10_02_000200_replace_open_ctl_growth_goals_with_consistency.php');

    $migration->up();
    $migration->down();

    expect($goal->fresh()->metric)->toBe('season_ctl_growth')
        ->and($goal->fresh()->unit)->toBe('CTL pts');
});
