<?php

declare(strict_types=1);

use App\Enums\ExperienceLevel;
use App\Enums\GoalType;
use App\Models\TrainingPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $preference = TrainingPreference::factory()->for($user)->create();

    expect($preference->user)->toBeInstanceOf(User::class)
        ->and($preference->user->is($user))->toBeTrue();
});

it('casts experience_level, sessions_per_week, goal_type, run_days and long_run_day', function (): void {
    $preference = TrainingPreference::factory()->make([
        'user_id' => 1,
        'experience_level' => 'returning',
        'sessions_per_week' => '4',
        'goal_type' => 'race',
        'run_days' => [1, 3, 5, 6],
        'long_run_day' => '6',
    ]);

    expect($preference->experience_level)->toBe(ExperienceLevel::Returning)
        ->and($preference->sessions_per_week)->toBeInt()->toBe(4)
        ->and($preference->goal_type)->toBe(GoalType::Race)
        ->and($preference->run_days)->toBe([1, 3, 5, 6])
        ->and($preference->long_run_day)->toBeInt()->toBe(6);
});

it('is reachable from a user via the trainingPreference relation', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create();

    expect($user->trainingPreference)->toBeInstanceOf(TrainingPreference::class);
});

it('allows every preference field to stay null', function (): void {
    $user = User::factory()->create();
    $preference = TrainingPreference::factory()->for($user)->create([
        'experience_level' => null,
        'sessions_per_week' => null,
        'goal_type' => null,
        'run_days' => null,
        'long_run_day' => null,
    ]);

    expect($preference->fresh()->experience_level)->toBeNull()
        ->and($preference->fresh()->sessions_per_week)->toBeNull()
        ->and($preference->fresh()->goal_type)->toBeNull()
        ->and($preference->fresh()->run_days)->toBeNull()
        ->and($preference->fresh()->long_run_day)->toBeNull();
});
