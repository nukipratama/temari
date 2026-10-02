<?php

declare(strict_types=1);

use App\Enums\RaceChangeKind;
use App\Enums\RaceOutcome;
use App\Models\RaceGoal;
use App\Models\RaceGoalChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('casts its kind, outcome and snapshot values', function (): void {
    $race = RaceGoal::factory()->create();

    $change = RaceGoalChange::query()->create([
        'race_goal_id' => $race->id,
        'user_id' => $race->user_id,
        'kind' => RaceChangeKind::Outcome,
        'race_date' => '2026-12-06',
        'goal_time_sec' => '3000',
        'outcome' => RaceOutcome::Confirmed,
        'finish_time_sec' => '2950',
    ])->fresh();

    expect($change->kind)->toBe(RaceChangeKind::Outcome)
        ->and($change->outcome)->toBe(RaceOutcome::Confirmed)
        ->and($change->race_date)->toBeInstanceOf(Carbon::class)
        ->and($change->goal_time_sec)->toBe(3000)
        ->and($change->finish_time_sec)->toBe(2950)
        ->and($change->created_at)->toBeInstanceOf(Carbon::class)
        ->and($change->raceGoal->is($race))->toBeTrue();
});

it('is listed oldest first on its race and goes with it', function (): void {
    $race = RaceGoal::factory()->create();
    foreach ([RaceChangeKind::Created, RaceChangeKind::Revised] as $kind) {
        RaceGoalChange::query()->create(['race_goal_id' => $race->id, 'user_id' => $race->user_id, 'kind' => $kind]);
    }

    expect($race->changes->pluck('kind')->all())->toBe([RaceChangeKind::Created, RaceChangeKind::Revised]);

    $race->delete();

    expect(RaceGoalChange::query()->count())->toBe(0);
});
