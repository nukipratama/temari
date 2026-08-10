<?php

declare(strict_types=1);

use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\SeasonGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();

    expect($season->user)->toBeInstanceOf(User::class)
        ->and($season->user->is($user))->toBeTrue();
});

it('optionally belongs to a race goal', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create();
    $season = Season::factory()->for($user)->create(['race_goal_id' => $race->id]);

    expect($season->raceGoal)->toBeInstanceOf(RaceGoal::class)
        ->and($season->raceGoal->is($race))->toBeTrue();
});

it('is self-scaled when it has no race goal', function (): void {
    $season = Season::factory()->create(['race_goal_id' => null]);

    expect($season->race_goal_id)->toBeNull();
});

it('casts starts_at and ends_at to dates', function (): void {
    $season = Season::factory()->make([
        'starts_at' => '2026-08-10',
        'ends_at' => '2026-11-02',
    ]);

    expect($season->starts_at)->toBeInstanceOf(Carbon::class)
        ->and($season->ends_at)->toBeInstanceOf(Carbon::class);
});

it('has many season goals', function (): void {
    $season = Season::factory()->create();
    SeasonGoal::factory()->for($season)->count(3)->create();

    expect($season->goals)->toHaveCount(3)
        ->and($season->goals->first())->toBeInstanceOf(SeasonGoal::class);
});
