<?php

declare(strict_types=1);

use App\Actions\Gamification\GrantSeasonUnlocksAction;
use App\Models\Season;
use App\Models\SeasonGoal;
use App\Models\User;
use App\Models\UserUnlock;
use App\Services\Gamification\SeasonGamificationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seasonUnlockCtx(int $restHonored): SeasonGamificationContext
{
    return new SeasonGamificationContext(
        sessionsCompleted: 0,
        qualityCompleted: 0,
        longestLongRunKm: 0.0,
        restHonored: $restHonored,
        raceGoalMet: false,
        ctlGrowth: 0.0,
    );
}

it('grants nothing below the first threshold', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();

    $new = app(GrantSeasonUnlocksAction::class)($user, $season, seasonUnlockCtx(2));

    expect($new)->toBe([])
        ->and(UserUnlock::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('grants the 3-day threshold once reached', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();

    $new = app(GrantSeasonUnlocksAction::class)($user, $season, seasonUnlockCtx(3));

    expect($new)->toBe(["season.{$season->id}.rest_honored_3"])
        ->and(UserUnlock::query()->where('user_id', $user->id)->where('unlock_key', "season.{$season->id}.rest_honored_3")->exists())->toBeTrue();
});

it('grants both thresholds at once when the count already clears the higher one', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();

    $new = app(GrantSeasonUnlocksAction::class)($user, $season, seasonUnlockCtx(7));

    expect($new)->toBe([
        "season.{$season->id}.rest_honored_3",
        "season.{$season->id}.rest_honored_7",
    ]);
});

it('is idempotent: a second call with the same count grants nothing new', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();
    app(GrantSeasonUnlocksAction::class)($user, $season, seasonUnlockCtx(3));

    $second = app(GrantSeasonUnlocksAction::class)($user, $season, seasonUnlockCtx(3));

    expect($second)->toBe([])
        ->and(UserUnlock::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('re-earns the threshold in a new season, distinct from the old season\'s unlock', function (): void {
    $user = User::factory()->create();
    $firstSeason = Season::factory()->for($user)->create();
    app(GrantSeasonUnlocksAction::class)($user, $firstSeason, seasonUnlockCtx(3));

    $secondSeason = Season::factory()->for($user)->create(['starts_at' => now()->addWeeks(12)->toDateString()]);
    $new = app(GrantSeasonUnlocksAction::class)($user, $secondSeason, seasonUnlockCtx(3));

    expect($new)->toBe(["season.{$secondSeason->id}.rest_honored_3"])
        ->and(UserUnlock::query()->where('user_id', $user->id)->count())->toBe(2);
});

function seasonGoal(Season $season, string $metric, float $target): void
{
    SeasonGoal::factory()->for($season)->create([
        'metric' => $metric,
        'target' => $target,
        'unit' => 'sessions',
    ]);
}

it('grants one track tier per completed season goal', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();
    seasonGoal($season, 'season_sessions_completed', 5.0);
    seasonGoal($season, 'season_quality_completed', 2.0);
    seasonGoal($season, 'season_longest_long_run_km', 30.0);

    $ctx = new SeasonGamificationContext(
        sessionsCompleted: 5,
        qualityCompleted: 2,
        longestLongRunKm: 4.0,
        restHonored: 0,
        raceGoalMet: false,
        ctlGrowth: 0.0,
    );

    $new = app(GrantSeasonUnlocksAction::class)($user, $season, $ctx);

    expect($new)->toBe(["season.{$season->id}.track_1", "season.{$season->id}.track_2"]);
});

it('grants no track tier while no season goal is complete', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();
    seasonGoal($season, 'season_sessions_completed', 5.0);

    $new = app(GrantSeasonUnlocksAction::class)($user, $season, seasonUnlockCtx(0));

    expect($new)->toBe([]);
});

it('re-earns the track in the next season and keeps the previous season\'s tiers', function (): void {
    $user = User::factory()->create();
    $first = Season::factory()->for($user)->create();
    seasonGoal($first, 'season_sessions_completed', 5.0);

    $ctx = new SeasonGamificationContext(
        sessionsCompleted: 5,
        qualityCompleted: 0,
        longestLongRunKm: 0.0,
        restHonored: 0,
        raceGoalMet: false,
        ctlGrowth: 0.0,
    );
    app(GrantSeasonUnlocksAction::class)($user, $first, $ctx);

    $second = Season::factory()->for($user)->create(['starts_at' => '2027-01-04', 'ends_at' => '2027-03-29']);
    seasonGoal($second, 'season_sessions_completed', 5.0);

    $new = app(GrantSeasonUnlocksAction::class)($user, $second, $ctx);

    expect($new)->toBe(["season.{$second->id}.track_1"])
        ->and(UserUnlock::query()->where('user_id', $user->id)->where('unlock_key', "season.{$first->id}.track_1")->exists())->toBeTrue();
});
