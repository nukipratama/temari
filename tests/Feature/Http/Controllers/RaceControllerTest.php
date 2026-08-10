<?php

declare(strict_types=1);

use App\Models\PersonalRecord;
use App\Models\RaceGoal;
use App\Models\User;
use App\Support\SharedPropCacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function racePayload(array $overrides = []): array
{
    return [
        'race_date' => now()->addWeeks(12)->toDateString(),
        'distance_m' => 10_000,
        'goal_time_sec' => 3_000,
        'name' => 'Jakarta 10K',
        ...$overrides,
    ];
}

it('requires authentication for the index', function (): void {
    $this->get('/race')->assertRedirect('/login');
});

it('requires authentication for store', function (): void {
    $this->post('/race', racePayload())->assertRedirect('/login');
});

it('renders the page with no race and no projection for a fresh user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/race')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Race')
            ->where('race', null)
            ->where('projection', null)
            ->where('ctlTrend', []));
});

it('renders the active race and its projection when one exists', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 1_500.0]);
    $race = RaceGoal::factory()->for($user)->create(['distance_m' => 10_000, 'goal_time_sec' => 3_100]);

    $this->actingAs($user)->get('/race')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Race')
            ->where('race.id', $race->id)
            ->where('race.distance_m', 10_000)
            ->where('projection.sample_size', 1)
            ->where('projection.confidence', 'low'));
});

it('never surfaces another user\'s race', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->create(); // another user's active race

    $this->actingAs($user)->get('/race')
        ->assertInertia(fn (Assert $page) => $page->where('race', null));
});

it('creates the first race for a user with none', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/race', racePayload())
        ->assertRedirect()
        ->assertSessionHas('success');

    $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();
    expect($race)->not->toBeNull()
        ->and($race->distance_m)->toBe(10_000)
        ->and($race->goal_time_sec)->toBe(3_000)
        ->and($race->name)->toBe('Jakarta 10K');
});

it('supersedes the current active race on a new submission, keeping history', function (): void {
    $user = User::factory()->create();
    $old = RaceGoal::factory()->for($user)->create(['name' => 'Old race']);

    $this->actingAs($user)
        ->post('/race', racePayload(['name' => 'New race']))
        ->assertRedirect();

    expect($old->fresh()->completed_at)->not->toBeNull();

    $active = RaceGoal::query()->where('user_id', $user->id)->active()->get();
    expect($active)->toHaveCount(1)
        ->and($active->first()->name)->toBe('New race');

    // History retained, not deleted.
    expect(RaceGoal::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('rejects an invalid submission and persists nothing', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/race', racePayload(['distance_m' => 100]))
        ->assertSessionHasErrors('distance_m');

    expect(RaceGoal::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('busts the shared active-race cache prop on store', function (): void {
    $user = User::factory()->create();
    $cacheKey = SharedPropCacheKey::ActiveRace->key($user->id);
    Cache::put($cacheKey, ['stale' => true]);

    $this->actingAs($user)->post('/race', racePayload())->assertRedirect();

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('shares the active race app-wide via the activeRace prop', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['name' => 'Shared race', 'distance_m' => 5_000]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeRace.name', 'Shared race')
            ->where('activeRace.distance_m', 5_000));
});
