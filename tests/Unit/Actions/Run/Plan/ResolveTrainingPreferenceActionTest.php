<?php

declare(strict_types=1);

use App\Actions\Run\Plan\ResolveTrainingPreferenceAction;
use App\Models\TrainingPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns the athlete\'s preferences row', function (): void {
    $user = User::factory()->create();
    $preference = TrainingPreference::factory()->for($user)->create();

    expect((app(ResolveTrainingPreferenceAction::class))($user->id)?->id)->toBe($preference->id);
});

it('reads the row once for repeated questions about the same athlete', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create();

    $resolve = new ResolveTrainingPreferenceAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $first = $resolve($user->id);
    $second = $resolve($user->id);

    expect($second)->toBe($first)
        ->and($queries)->toBe(1);
});

// An athlete who has never opened Settings has no row, and that absence is an
// answer the plan engine asks for four times per render.
it('memoizes the absence of a row too', function (): void {
    $user = User::factory()->create();

    $resolve = new ResolveTrainingPreferenceAction();
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
    $firstPreference = TrainingPreference::factory()->for($first)->create();
    $secondPreference = TrainingPreference::factory()->for($second)->create();

    $resolve = new ResolveTrainingPreferenceAction();

    expect($resolve($first->id)?->id)->toBe($firstPreference->id)
        ->and($resolve($second->id)?->id)->toBe($secondPreference->id);
});

it('re-reads after forget', function (): void {
    $user = User::factory()->create();

    $resolve = new ResolveTrainingPreferenceAction();
    expect($resolve($user->id))->toBeNull();

    $created = TrainingPreference::factory()->for($user)->create();
    $resolve->forget($user->id);

    expect($resolve($user->id)?->id)->toBe($created->id);
});

it('drops the shared memo when a row is saved or deleted', function (): void {
    $user = User::factory()->create();
    $resolve = app(ResolveTrainingPreferenceAction::class);

    expect($resolve($user->id))->toBeNull();

    $preference = TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 3]);
    expect($resolve($user->id)?->sessions_per_week)->toBe(3);

    $preference->update(['sessions_per_week' => 5]);
    expect($resolve($user->id)?->sessions_per_week)->toBe(5);

    $preference->delete();
    expect($resolve($user->id))->toBeNull();
});

it('is one shared instance per request', function (): void {
    expect(app(ResolveTrainingPreferenceAction::class))->toBe(app(ResolveTrainingPreferenceAction::class));
});
