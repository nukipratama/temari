<?php

declare(strict_types=1);

use App\Actions\Run\Plan\ResolveSeasonAction;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns the latest season by start date', function (): void {
    $user = User::factory()->create();
    Season::factory()->for($user)->create(['starts_at' => Carbon::today()->subMonths(4)]);
    $latest = Season::factory()->for($user)->create(['starts_at' => Carbon::today()->subMonth()]);

    expect(app(ResolveSeasonAction::class)->latest($user->id)?->id)->toBe($latest->id);
});

it('reads the latest season once for repeated questions', function (): void {
    $user = User::factory()->create();
    Season::factory()->for($user)->create(['starts_at' => Carbon::today()->subMonth()]);

    $resolve = new ResolveSeasonAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $first = $resolve->latest($user->id);
    $second = $resolve->latest($user->id);

    expect($second)->toBe($first)
        ->and($queries)->toBe(1);
});

it('memoizes the absence of a season too', function (): void {
    $user = User::factory()->create();

    $resolve = new ResolveSeasonAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $resolve->latest($user->id);
    $resolve->latest($user->id);

    expect($queries)->toBe(1);
});

// The dated read is the same answer as the latest one whenever that season has
// already started, which is every season SeasonService opens.
it('serves the arc covering today off the latest season, without a second read', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create(['starts_at' => Carbon::today()->subMonth()]);

    $resolve = new ResolveSeasonAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect($resolve->latest($user->id)?->id)->toBe($season->id)
        ->and($resolve->currentAsOf($user->id, Carbon::today())?->id)->toBe($season->id)
        ->and($queries)->toBe(1);
});

// A date before the latest season started must not be told about it — a late
// compliance verdict is measured against the arc it was actually prescribed
// under.
it('reads again for a date the latest season does not cover', function (): void {
    $user = User::factory()->create();
    $older = Season::factory()->for($user)->create(['starts_at' => Carbon::today()->subMonths(4)]);
    Season::factory()->for($user)->create(['starts_at' => Carbon::today()->subMonth()]);

    $resolve = new ResolveSeasonAction();

    expect($resolve->currentAsOf($user->id, Carbon::today()->subMonths(2))?->id)->toBe($older->id);
});

it('reads a given date once', function (): void {
    $user = User::factory()->create();
    Season::factory()->for($user)->create(['starts_at' => Carbon::today()->subMonth()]);
    $asOf = Carbon::today()->subMonths(2);

    $resolve = new ResolveSeasonAction();
    $resolve->currentAsOf($user->id, $asOf);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $resolve->currentAsOf($user->id, $asOf);

    expect($queries)->toBe(0);
});

it('keeps athletes apart', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $firstSeason = Season::factory()->for($first)->create(['starts_at' => Carbon::today()->subMonth()]);

    $resolve = new ResolveSeasonAction();

    expect($resolve->latest($first->id)?->id)->toBe($firstSeason->id)
        ->and($resolve->latest($second->id))->toBeNull();
});

it('re-reads both memos after forget', function (): void {
    $user = User::factory()->create();

    $resolve = new ResolveSeasonAction();
    expect($resolve->latest($user->id))->toBeNull()
        ->and($resolve->currentAsOf($user->id, Carbon::today()))->toBeNull();

    $created = Season::factory()->for($user)->create(['starts_at' => Carbon::today()]);
    $resolve->forget($user->id);

    expect($resolve->latest($user->id)?->id)->toBe($created->id)
        ->and($resolve->currentAsOf($user->id, Carbon::today())?->id)->toBe($created->id);
});

it('drops the shared memo when a season is saved or deleted', function (): void {
    $user = User::factory()->create();
    $resolve = app(ResolveSeasonAction::class);

    expect($resolve->latest($user->id))->toBeNull();

    $season = Season::factory()->for($user)->create([
        'starts_at' => Carbon::today(),
        'anchor_weekly_volume_km' => 30.0,
    ]);
    expect($resolve->latest($user->id)?->anchor_weekly_volume_km)->toBe(30.0);

    $season->update(['anchor_weekly_volume_km' => 45.0]);
    expect($resolve->latest($user->id)?->anchor_weekly_volume_km)->toBe(45.0);

    $season->delete();
    expect($resolve->latest($user->id))->toBeNull();
});

it('is one shared instance per request', function (): void {
    expect(app(ResolveSeasonAction::class))->toBe(app(ResolveSeasonAction::class));
});
