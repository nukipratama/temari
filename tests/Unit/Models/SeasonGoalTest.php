<?php

declare(strict_types=1);

use App\Models\Season;
use App\Models\SeasonGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('belongs to a season', function (): void {
    $season = Season::factory()->create();
    $goal = SeasonGoal::factory()->for($season)->create();

    expect($goal->season)->toBeInstanceOf(Season::class)
        ->and($goal->season->is($season))->toBeTrue();
});

it('casts target to a float', function (): void {
    $goal = SeasonGoal::factory()->make(['target' => '12']);

    expect($goal->target)->toBeFloat()->toBe(12.0);
});

it('allows a null metric_key', function (): void {
    $goal = SeasonGoal::factory()->make(['metric_key' => null]);

    expect($goal->metric_key)->toBeNull();
});
