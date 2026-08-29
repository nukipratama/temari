<?php

declare(strict_types=1);

use App\Models\RaceGoal;
use App\Models\TrainingPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function onboardingGoalPayload(array $overrides = []): array
{
    return [
        'race_date' => now()->addWeeks(12)->toDateString(),
        'distance_m' => 10_000,
        'goal_time_sec' => 3_000,
        'name' => 'Jakarta 10K',
        ...$overrides,
    ];
}

it('requires authentication for the wizard', function (): void {
    $this->get('/onboarding')->assertRedirect('/login');
    $this->post('/onboarding')->assertRedirect('/login');
});

it('shows the wizard to a user who has not onboarded', function (): void {
    $user = User::factory()->needsOnboarding()->create();

    $this->actingAs($user)->get('/onboarding')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Onboarding/Index'));
});

it('never redirects an already-onboarded user back into the wizard', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/onboarding')->assertRedirect(route('dashboard'));
});

it('redirects an unboarded user away from the rest of the app back to the wizard', function (): void {
    $user = User::factory()->needsOnboarding()->create();

    $this->actingAs($user)->get('/')->assertRedirect(route('onboarding.show'));
    $this->actingAs($user)->get('/history')->assertRedirect(route('onboarding.show'));
});

it('lets an unboarded user log out without hitting the onboarding gate', function (): void {
    $user = User::factory()->needsOnboarding()->create();

    $this->actingAs($user)->post(route('auth.logout'))->assertRedirect(route('login'));
});

it('marks the user onboarded and skips the goal step when no race fields are submitted', function (): void {
    $user = User::factory()->needsOnboarding()->create();

    $this->actingAs($user)
        ->post('/onboarding')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success');

    expect($user->fresh()->onboarded_at)->not->toBeNull()
        ->and(RaceGoal::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('creates a race and marks the user onboarded when the goal step is filled in', function (): void {
    $user = User::factory()->needsOnboarding()->create();

    $this->actingAs($user)
        ->post('/onboarding', onboardingGoalPayload())
        ->assertRedirect(route('dashboard'));

    $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();
    expect($race)->not->toBeNull()
        ->and($race->distance_m)->toBe(10_000)
        ->and($race->goal_time_sec)->toBe(3_000)
        ->and($race->name)->toBe('Jakarta 10K')
        ->and($user->fresh()->onboarded_at)->not->toBeNull();
});

it('rejects a partial goal submission and does not onboard the user', function (): void {
    $user = User::factory()->needsOnboarding()->create();

    $this->actingAs($user)
        ->post('/onboarding', onboardingGoalPayload(['distance_m' => null]))
        ->assertSessionHasErrors('distance_m');

    expect($user->fresh()->onboarded_at)->toBeNull()
        ->and(RaceGoal::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('does not create a second active race on a retried submit', function (): void {
    $user = User::factory()->needsOnboarding()->create();

    $this->actingAs($user)->post('/onboarding', onboardingGoalPayload())->assertRedirect(route('dashboard'));
    // Simulate a retried/double submit after the user is already onboarded.
    $user->refresh();
    $this->actingAs($user)->post('/onboarding', onboardingGoalPayload())->assertRedirect(route('dashboard'));

    expect(RaceGoal::query()->where('user_id', $user->id)->active()->count())->toBe(1);
});

it('lets an already-onboarded user reach the rest of the app', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertSuccessful();
});

it('creates a training_preferences row when the preferences step is filled in', function (): void {
    $user = User::factory()->needsOnboarding()->create();

    $this->actingAs($user)
        ->post('/onboarding', [
            'experience_level' => 'new_to_running',
            'sessions_per_week' => 3,
            'goal_type' => 'consistent',
            'run_days' => [1, 3, 5],
            'long_run_day' => 5,
        ])
        ->assertRedirect(route('dashboard'));

    $preference = TrainingPreference::query()->where('user_id', $user->id)->first();
    expect($preference)->not->toBeNull()
        ->and($preference->sessions_per_week)->toBe(3)
        ->and($preference->run_days)->toBe([1, 3, 5])
        ->and($user->fresh()->onboarded_at)->not->toBeNull();
});

it('creates no training_preferences row when the preferences step is skipped', function (): void {
    $user = User::factory()->needsOnboarding()->create();

    $this->actingAs($user)->post('/onboarding')->assertRedirect(route('dashboard'));

    expect(TrainingPreference::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
