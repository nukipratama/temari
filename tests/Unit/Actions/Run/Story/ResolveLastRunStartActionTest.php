<?php

declare(strict_types=1);

use App\Actions\Run\Story\ResolveLastRunStartAction;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

$seedRun = static function (User $user, string $startedAt): ActivityDetail {
    $activity = Activity::factory()->for($user)->analyzed()->create();

    return ActivityDetail::factory()->for($activity)->create(['start_date_local' => $startedAt]);
};

it('returns the latest run start', function () use ($seedRun): void {
    $user = User::factory()->create();
    $seedRun($user, '2026-09-01 06:00:00');
    $seedRun($user, '2026-09-08 06:00:00');

    $start = (new ResolveLastRunStartAction())($user->id);

    expect($start?->toDateTimeString())->toBe('2026-09-08 06:00:00');
});

it('returns null when the athlete has no dated run', function (): void {
    $user = User::factory()->create();

    expect((new ResolveLastRunStartAction())($user->id))->toBeNull()
        ->and((new ResolveLastRunStartAction())($user->id, Carbon::parse('2026-09-08 23:59:59')))->toBeNull();
});

it('reads once for the unbounded question and any ceiling the latest run clears', function () use ($seedRun): void {
    $user = User::factory()->create();
    $seedRun($user, '2026-09-08 06:00:00');

    $resolve = new ResolveLastRunStartAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $resolve($user->id);
    $resolve($user->id, Carbon::parse('2026-09-10 23:59:59'));
    $resolve($user->id, Carbon::parse('2026-09-09 23:59:59'));

    expect($queries)->toBe(1);
});

// A self-heal recompute reads a past-dated briefing; a run logged since must
// not leak into it and misreport recovery.
it('honours a ceiling that excludes the latest run', function () use ($seedRun): void {
    $user = User::factory()->create();
    $seedRun($user, '2026-09-01 06:00:00');
    $seedRun($user, '2026-09-08 06:00:00');

    $resolve = new ResolveLastRunStartAction();
    $start = $resolve($user->id, Carbon::parse('2026-09-03 23:59:59'));

    expect($start?->toDateTimeString())->toBe('2026-09-01 06:00:00');
});

it('hands every caller its own Carbon', function () use ($seedRun): void {
    $user = User::factory()->create();
    $seedRun($user, '2026-09-08 06:00:00');

    $resolve = new ResolveLastRunStartAction();
    $first = $resolve($user->id);
    $first?->startOfDay();

    expect($resolve($user->id)?->toDateTimeString())->toBe('2026-09-08 06:00:00');
});

it('drops the shared memo when a detail row is written or an activity deleted', function () use ($seedRun): void {
    $user = User::factory()->create();
    $resolve = app(ResolveLastRunStartAction::class);

    expect($resolve($user->id))->toBeNull();

    $detail = $seedRun($user, '2026-09-08 06:00:00');
    expect($resolve($user->id)?->toDateTimeString())->toBe('2026-09-08 06:00:00');

    $detail->activity->delete();
    expect($resolve($user->id))->toBeNull();
});

it('is one shared instance per request', function (): void {
    expect(app(ResolveLastRunStartAction::class))->toBe(app(ResolveLastRunStartAction::class));
});
