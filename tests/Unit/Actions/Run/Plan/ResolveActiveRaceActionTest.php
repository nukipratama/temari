<?php

declare(strict_types=1);

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Models\RaceGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns the athlete\'s one active race', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['completed_at' => now()]);
    $active = RaceGoal::factory()->for($user)->create(['completed_at' => null]);

    expect((app(ResolveActiveRaceAction::class))($user->id)?->id)->toBe($active->id);
});

it('returns null for an athlete with no active race', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['completed_at' => now()]);

    expect((app(ResolveActiveRaceAction::class))($user->id))->toBeNull();
});

it('reads the row once for repeated questions about the same athlete', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['completed_at' => null]);

    $resolve = new ResolveActiveRaceAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $first = $resolve($user->id);
    $second = $resolve($user->id);

    expect($second)->toBe($first)
        ->and($queries)->toBe(1);
});

// A null answer is an answer. Memoized by presence rather than truthiness, or
// every page an athlete without a race loads pays the read on each collaborator.
it('memoizes the absence of a race too', function (): void {
    $user = User::factory()->create();

    $resolve = new ResolveActiveRaceAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $resolve($user->id);
    $resolve($user->id);

    expect($queries)->toBe(1);
});

it('keeps athletes apart', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $firstRace = RaceGoal::factory()->for($first)->create(['completed_at' => null]);
    $secondRace = RaceGoal::factory()->for($second)->create(['completed_at' => null]);

    $resolve = new ResolveActiveRaceAction();

    expect($resolve($first->id)?->id)->toBe($firstRace->id)
        ->and($resolve($second->id)?->id)->toBe($secondRace->id);
});

// RaceController retires a race with a mass update(), which fires no model
// events — so the memo has to be dropped by hand or the periodizer it calls
// straight afterwards replans against the race that was just cleared.
it('re-reads after forget', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['completed_at' => null]);

    $resolve = new ResolveActiveRaceAction();
    expect($resolve($user->id)?->id)->toBe($race->id);

    RaceGoal::query()->where('user_id', $user->id)->update(['completed_at' => now()]);
    $resolve->forget($user->id);

    expect($resolve($user->id))->toBeNull();
});

it('is one shared instance per request', function (): void {
    expect(app(ResolveActiveRaceAction::class))->toBe(app(ResolveActiveRaceAction::class));
});
