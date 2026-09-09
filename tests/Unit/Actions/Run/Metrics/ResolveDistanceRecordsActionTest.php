<?php

declare(strict_types=1);

use App\Actions\Run\Metrics\ResolveDistanceRecordsAction;
use App\Enums\PrCategory;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns only the standard race distances', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create(['category' => PrCategory::Km5]);
    PersonalRecord::factory()->for($user)->create(['category' => PrCategory::Best5Min]);

    $records = (app(ResolveDistanceRecordsAction::class))($user->id);

    expect($records)->toHaveCount(1)
        ->and($records->first()->category)->toBe(PrCategory::Km5);
});

it('reads the set once for repeated questions about the same athlete', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create(['category' => PrCategory::Km5]);

    $resolve = new ResolveDistanceRecordsAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $first = $resolve($user->id);
    $second = $resolve($user->id);

    expect($second)->toBe($first)
        ->and($queries)->toBe(1);
});

it('memoizes an empty set too', function (): void {
    $user = User::factory()->create();

    $resolve = new ResolveDistanceRecordsAction();
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
    PersonalRecord::factory()->for($first)->create(['category' => PrCategory::Km5]);

    $resolve = new ResolveDistanceRecordsAction();

    expect($resolve($first->id))->toHaveCount(1)
        ->and($resolve($second->id))->toHaveCount(0);
});

// PersonalRecords::rebuildForUser drops the whole set with a mass delete(),
// which fires no model events.
it('re-reads after forget', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create(['category' => PrCategory::Km5]);

    $resolve = new ResolveDistanceRecordsAction();
    expect($resolve($user->id))->toHaveCount(1);

    PersonalRecord::query()->where('user_id', $user->id)->delete();
    $resolve->forget($user->id);

    expect($resolve($user->id))->toHaveCount(0);
});

it('drops the shared memo when a record is saved or deleted', function (): void {
    $user = User::factory()->create();
    $resolve = app(ResolveDistanceRecordsAction::class);

    expect($resolve($user->id))->toHaveCount(0);

    $record = PersonalRecord::factory()->for($user)->create(['category' => PrCategory::Km5]);
    expect($resolve($user->id))->toHaveCount(1);

    $record->delete();
    expect($resolve($user->id))->toHaveCount(0);
});

it('is one shared instance per request', function (): void {
    expect(app(ResolveDistanceRecordsAction::class))->toBe(app(ResolveDistanceRecordsAction::class));
});
