<?php

declare(strict_types=1);

use App\Enums\ExperienceLevel;
use App\Enums\GoalType;
use App\Models\TrainingPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function validPreferencesPayload(array $overrides = []): array
{
    return [
        'experience_level' => 'returning',
        'sessions_per_week' => 4,
        'goal_type' => 'race',
        'run_days' => [1, 3, 5, 6],
        'long_run_day' => 6,
        ...$overrides,
    ];
}

it('requires authentication for the update', function (): void {
    $this->patch('/settings/training-preferences', validPreferencesPayload())->assertRedirect('/login');
});

it('creates a training_preferences row', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/training-preferences', validPreferencesPayload())
        ->assertRedirect()
        ->assertSessionHas('success');

    $preference = TrainingPreference::query()->where('user_id', $user->id)->firstOrFail();

    expect($preference->experience_level)->toBe(ExperienceLevel::Returning)
        ->and($preference->sessions_per_week)->toBe(4)
        ->and($preference->goal_type)->toBe(GoalType::Race)
        ->and($preference->run_days)->toBe([1, 3, 5, 6])
        ->and($preference->long_run_day)->toBe(6);
});

it('updates the existing row in place rather than creating a second one', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 3]);

    $this->actingAs($user)
        ->patch('/settings/training-preferences', validPreferencesPayload(['sessions_per_week' => 5, 'run_days' => [0, 1, 3, 5, 6]]))
        ->assertRedirect();

    expect(TrainingPreference::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(TrainingPreference::query()->where('user_id', $user->id)->value('sessions_per_week'))->toBe(5);
});

it('clears a field back to null, handing control back to the behavioral fallback', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 5, 'run_days' => [0, 1, 3, 5, 6], 'long_run_day' => 6]);

    $this->actingAs($user)
        ->patch('/settings/training-preferences', [])
        ->assertRedirect();

    $preference = TrainingPreference::query()->where('user_id', $user->id)->firstOrFail();
    expect($preference->sessions_per_week)->toBeNull()
        ->and($preference->run_days)->toBeNull()
        ->and($preference->long_run_day)->toBeNull();
});

it('rejects a run_days count that does not match sessions_per_week', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/training-preferences', validPreferencesPayload(['sessions_per_week' => 4, 'run_days' => [1, 3, 5]]))
        ->assertSessionHasErrors('run_days');

    expect(TrainingPreference::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('rejects a long_run_day that is not one of the chosen run_days', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/training-preferences', validPreferencesPayload(['run_days' => [1, 3, 5, 6], 'long_run_day' => 2]))
        ->assertSessionHasErrors('long_run_day');
});

it('rejects a sessions_per_week outside the supported 2-6 range', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/training-preferences', validPreferencesPayload(['sessions_per_week' => 7, 'run_days' => null, 'long_run_day' => null]))
        ->assertSessionHasErrors('sessions_per_week');
});
