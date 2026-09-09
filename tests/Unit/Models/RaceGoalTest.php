<?php

declare(strict_types=1);

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Models\RaceGoal;
use App\Models\User;
use App\Support\SharedPropCacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create();

    expect($race->user)->toBeInstanceOf(User::class)
        ->and($race->user->is($user))->toBeTrue();
});

it('casts race_date, distance_m, goal_time_sec and completed_at', function (): void {
    $race = RaceGoal::factory()->make([
        'user_id' => 1,
        'race_date' => '2026-12-06',
        'distance_m' => '21097',
        'goal_time_sec' => '7200',
        'completed_at' => '2026-01-01 00:00:00',
    ]);

    expect($race->race_date)->toBeInstanceOf(Carbon::class)
        ->and($race->distance_m)->toBeInt()->toBe(21097)
        ->and($race->goal_time_sec)->toBeInt()->toBe(7200)
        ->and($race->completed_at)->toBeInstanceOf(Carbon::class);
});

it('serializes race_date as the naive date, not a UTC-shifted instant', function (): void {
    $race = new RaceGoal(['race_date' => '2026-12-06']);

    expect($race->toArray()['race_date'])->toBe('2026-12-06');
});

it('active scope excludes races with a completed_at', function (): void {
    $user = User::factory()->create();
    $active = RaceGoal::factory()->for($user)->create();
    RaceGoal::factory()->for($user)->completed()->create();

    expect(RaceGoal::query()->where('user_id', $user->id)->active()->pluck('id')->all())
        ->toBe([$active->id]);
});

it('forgets the shared active-race cache prop on create, update and delete', function (): void {
    $user = User::factory()->create();
    $cacheKey = SharedPropCacheKey::ActiveRace->key($user->id);

    Cache::put($cacheKey, 'stale-on-create');
    $race = RaceGoal::factory()->for($user)->create();
    expect(Cache::has($cacheKey))->toBeFalse();

    Cache::put($cacheKey, 'stale-on-update');
    $race->update(['name' => 'Renamed']);
    expect(Cache::has($cacheKey))->toBeFalse();

    Cache::put($cacheKey, 'stale-on-delete');
    $race->delete();
    expect(Cache::has($cacheKey))->toBeFalse();
});

// The per-request memo is the second reader of the same fact. Setting a race
// mid-request (onboarding, then the periodizer it calls) has to be seen by it,
// or the plan is rebuilt against the race that was just replaced.
it('forgets the per-request active-race memo on create, update and delete', function (): void {
    $user = User::factory()->create();
    $resolve = app(ResolveActiveRaceAction::class);

    expect($resolve($user->id))->toBeNull();

    $race = RaceGoal::factory()->for($user)->create(['completed_at' => null]);
    expect($resolve($user->id)?->id)->toBe($race->id);

    $race->update(['completed_at' => now()]);
    expect($resolve($user->id))->toBeNull();

    $revived = RaceGoal::factory()->for($user)->create(['completed_at' => null]);
    expect($resolve($user->id)?->id)->toBe($revived->id);

    $revived->delete();
    expect($resolve($user->id))->toBeNull();
});
