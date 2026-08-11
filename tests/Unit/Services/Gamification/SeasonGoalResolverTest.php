<?php

declare(strict_types=1);

use App\Models\Season;
use App\Models\SeasonGoal;
use App\Models\User;
use App\Services\Gamification\SeasonGamificationContext;
use App\Services\Gamification\SeasonGoalResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->resolver = app(SeasonGoalResolver::class);
});
afterEach(fn () => Carbon::setTestNow());

function seasonCtx(array $overrides = []): SeasonGamificationContext
{
    return new SeasonGamificationContext(
        sessionsCompleted: $overrides['sessionsCompleted'] ?? 0,
        qualityCompleted: $overrides['qualityCompleted'] ?? 0,
        longestLongRunKm: $overrides['longestLongRunKm'] ?? 0.0,
        restHonored: $overrides['restHonored'] ?? 0,
        raceGoalMet: $overrides['raceGoalMet'] ?? false,
        ctlGrowth: $overrides['ctlGrowth'] ?? 0.0,
    );
}

it('resolves currentValue for every season metric', function (): void {
    $ctx = seasonCtx([
        'sessionsCompleted' => 4,
        'qualityCompleted' => 2,
        'longestLongRunKm' => 15.5,
        'restHonored' => 3,
        'raceGoalMet' => true,
        'ctlGrowth' => 5.2,
    ]);

    expect($this->resolver->currentValue($ctx, 'season_sessions_completed'))->toBe(4)
        ->and($this->resolver->currentValue($ctx, 'season_quality_completed'))->toBe(2)
        ->and($this->resolver->currentValue($ctx, 'season_longest_long_run_km'))->toBe(15.5)
        ->and($this->resolver->currentValue($ctx, 'season_rest_honored'))->toBe(3)
        ->and($this->resolver->currentValue($ctx, 'season_race_goal_met'))->toBe(1)
        ->and($this->resolver->currentValue($ctx, 'season_ctl_growth'))->toBe(5.2);
});

it('resolves season_race_goal_met to 0 when not met', function (): void {
    expect($this->resolver->currentValue(seasonCtx(), 'season_race_goal_met'))->toBe(0);
});

it('throws on an unknown metric', function (): void {
    expect(fn () => $this->resolver->currentValue(seasonCtx(), 'not_a_real_metric'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves every season goal to a current/target/unit/is_completed shape, capped at target', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();
    SeasonGoal::factory()->for($season)->create([
        'title' => 'Complete your planned sessions',
        'metric' => 'season_sessions_completed',
        'target' => 5,
        'unit' => 'sessions',
    ]);

    $ctx = seasonCtx(['sessionsCompleted' => 8]); // exceeds target

    $goals = $this->resolver->forSeason($user, $season, $ctx);

    expect($goals)->toHaveCount(1)
        ->and($goals[0]['title'])->toBe('Complete your planned sessions')
        ->and($goals[0]['current'])->toBe(5.0) // clamped to target, same as GoalResolver
        ->and($goals[0]['target'])->toBe(5.0)
        ->and($goals[0]['unit'])->toBe('sessions')
        ->and($goals[0]['is_completed'])->toBeTrue();
});

it('marks a goal incomplete when current is below target', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();
    SeasonGoal::factory()->for($season)->create([
        'metric' => 'season_sessions_completed',
        'target' => 10,
    ]);

    $goals = $this->resolver->forSeason($user, $season, seasonCtx(['sessionsCompleted' => 3]));

    expect($goals[0]['is_completed'])->toBeFalse()
        ->and($goals[0]['current'])->toBe(3);
});
