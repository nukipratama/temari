<?php

declare(strict_types=1);

use App\Actions\Run\Plan\ResolveTrailingWeeksAction;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns the window behind the date, newest first', function (): void {
    $user = User::factory()->create();
    foreach ([1, 2, 3, 4] as $weeksAgo) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($weeksAgo)->toDateString(),
        ]);
    }
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->addWeek()->toDateString(),
    ]);

    $weeks = (app(ResolveTrailingWeeksAction::class))($user->id, Carbon::today()->toDateString(), 2);

    expect($weeks)->toHaveCount(2)
        ->and($weeks->first()->week_ending->toDateString())->toBe(Carbon::today()->subWeek()->toDateString());
});

it('reads the window once for repeated questions with the same key', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => Carbon::today()->toDateString()]);

    $resolve = new ResolveTrailingWeeksAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $first = $resolve($user->id, Carbon::today()->toDateString(), 6);
    $second = $resolve($user->id, Carbon::today()->toDateString(), 6);

    expect($second->pluck('id')->all())->toBe($first->pluck('id')->all())
        ->and($queries)->toBe(1);
});

// Compliance scoring measures a past week against its own trailing window, so
// the week is part of the answer, not just the athlete. Depth is not: a deeper
// window behind the same week is a slice of the read already made.
it('keeps different weeks apart and serves every depth from one read', function (): void {
    $user = User::factory()->create();
    $resolve = new ResolveTrailingWeeksAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $resolve($user->id, Carbon::today()->toDateString(), 6);
    $resolve($user->id, Carbon::today()->toDateString(), 12);
    $resolve($user->id, Carbon::today()->subWeek()->toDateString(), 6);

    expect($queries)->toBe(2);
});

// The read is widened to the week's end so Home's three depths share it, which
// would leak the in-progress week into a window asked for as of a day inside it.
it('excludes a snapshot ending later in the same week as the date', function (): void {
    $user = User::factory()->create();
    $wednesday = Carbon::parse('2026-09-09');
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-09-13']);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-09-06']);

    $weeks = (new ResolveTrailingWeeksAction())($user->id, $wednesday->toDateString(), 6);

    expect($weeks)->toHaveCount(1)
        ->and($weeks->first()->week_ending->toDateString())->toBe('2026-09-06');
});

it('keeps athletes apart', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    WeeklySnapshot::factory()->for($first)->create(['week_ending' => Carbon::today()->toDateString()]);

    $resolve = new ResolveTrailingWeeksAction();

    expect($resolve($first->id, Carbon::today()->toDateString(), 6))->toHaveCount(1)
        ->and($resolve($second->id, Carbon::today()->toDateString(), 6))->toHaveCount(0);
});

// CleanupDeletedActivityJob drops forward snapshots with a mass delete(), which
// fires no model events.
it('re-reads every date after forget', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => Carbon::today()->toDateString()]);

    $resolve = new ResolveTrailingWeeksAction();
    expect($resolve($user->id, Carbon::today()->toDateString(), 6))->toHaveCount(1);

    WeeklySnapshot::query()->where('user_id', $user->id)->delete();
    $resolve->forget($user->id);

    expect($resolve($user->id, Carbon::today()->toDateString(), 6))->toHaveCount(0);
});

it('drops the shared memo when a snapshot is saved or deleted', function (): void {
    $user = User::factory()->create();
    $resolve = app(ResolveTrailingWeeksAction::class);
    $today = Carbon::today()->toDateString();

    expect($resolve($user->id, $today, 6))->toHaveCount(0);

    $snapshot = WeeklySnapshot::factory()->for($user)->create(['week_ending' => $today]);
    expect($resolve($user->id, $today, 6))->toHaveCount(1);

    $snapshot->delete();
    expect($resolve($user->id, $today, 6))->toHaveCount(0);
});

it('is one shared instance per request', function (): void {
    expect(app(ResolveTrailingWeeksAction::class))->toBe(app(ResolveTrailingWeeksAction::class));
});
