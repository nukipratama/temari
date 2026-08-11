<?php

declare(strict_types=1);

use App\Actions\Gamification\GrantSeasonUnlocksAction;
use App\Models\Season;
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
