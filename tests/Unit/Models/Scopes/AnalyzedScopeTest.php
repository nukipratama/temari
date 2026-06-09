<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('hides un-analyzed stubs from default Activity queries', function (): void {
    $user = User::factory()->create();
    $analyzed = Activity::factory()->for($user)->analyzed()->create();
    Activity::factory()->for($user)->stub()->create();

    expect(Activity::query()->pluck('id')->all())->toBe([$analyzed->id])
        ->and(Activity::query()->count())->toBe(1);
});

it('reveals stubs only when withStubs() opts out of the scope', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->analyzed()->create();
    Activity::factory()->for($user)->stub()->create();

    expect(Activity::query()->withStubs()->count())->toBe(2)
        ->and(Activity::query()->count())->toBe(1);
});

it('hides a stub from find() but withStubs()->find() resolves it', function (): void {
    $stub = Activity::factory()->stub()->create();

    expect(Activity::find($stub->id))->toBeNull()
        ->and(Activity::query()->withStubs()->find($stub->id)?->id)->toBe($stub->id);
});

it('excludes stubs from a user activities relationship', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->analyzed()->count(2)->create();
    Activity::factory()->for($user)->stub()->create();

    expect($user->activities()->count())->toBe(2);
});

it('excludes stubs inside a whereHas(activity) subquery', function (): void {
    // The scope must apply inside relationship subqueries too, so callers that
    // whereHas('activity', ...) can drop the explicit analyzed_at filter.
    $user = User::factory()->create();
    $analyzed = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($analyzed)->create();
    $stub = Activity::factory()->for($user)->stub()->create();
    ActivityDetail::factory()->for($stub)->create();

    $matched = ActivityDetail::query()
        ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
        ->count();

    expect($matched)->toBe(1);
});
