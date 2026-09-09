<?php

declare(strict_types=1);

use App\Actions\Run\Plan\ResolveWeekAdaptationAction;
use App\Models\PlanAdaptation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns the adaptation for the week asked about', function (): void {
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();
    $adaptation = PlanAdaptation::factory()->for($user)->create(['week_start' => $weekStart]);
    PlanAdaptation::factory()->for($user)->create([
        'week_start' => Carbon::parse($weekStart)->subWeek()->toDateString(),
    ]);

    expect((app(ResolveWeekAdaptationAction::class))($user->id, $weekStart)?->id)->toBe($adaptation->id);
});

it('reads the row once for repeated questions about the same week', function (): void {
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();
    PlanAdaptation::factory()->for($user)->create(['week_start' => $weekStart]);

    $resolve = new ResolveWeekAdaptationAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $first = $resolve($user->id, $weekStart);
    $second = $resolve($user->id, $weekStart);

    expect($second)->toBe($first)
        ->and($queries)->toBe(1);
});

// A week with no adaptation row is the common case, and the Plan page asks
// three times.
it('memoizes the absence of a row too', function (): void {
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();

    $resolve = new ResolveWeekAdaptationAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $resolve($user->id, $weekStart);
    $resolve($user->id, $weekStart);

    expect($queries)->toBe(1);
});

it('keeps athletes and weeks apart', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();
    $firstAdaptation = PlanAdaptation::factory()->for($first)->create(['week_start' => $weekStart]);

    $resolve = new ResolveWeekAdaptationAction();

    expect($resolve($first->id, $weekStart)?->id)->toBe($firstAdaptation->id)
        ->and($resolve($second->id, $weekStart))->toBeNull()
        ->and($resolve($first->id, Carbon::parse($weekStart)->subWeek()->toDateString()))->toBeNull();
});

it('re-reads every week after forget', function (): void {
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();

    $resolve = new ResolveWeekAdaptationAction();
    expect($resolve($user->id, $weekStart))->toBeNull();

    $created = PlanAdaptation::factory()->for($user)->create(['week_start' => $weekStart]);
    $resolve->forget($user->id);

    expect($resolve($user->id, $weekStart)?->id)->toBe($created->id);
});

it('drops the shared memo when a row is saved or deleted', function (): void {
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();
    $resolve = app(ResolveWeekAdaptationAction::class);

    expect($resolve($user->id, $weekStart))->toBeNull();

    $adaptation = PlanAdaptation::factory()->for($user)->create([
        'week_start' => $weekStart,
        'adherence_pct' => 40,
    ]);
    expect($resolve($user->id, $weekStart)?->adherence_pct)->toBe(40);

    $adaptation->update(['adherence_pct' => 90]);
    expect($resolve($user->id, $weekStart)?->adherence_pct)->toBe(90);

    $adaptation->delete();
    expect($resolve($user->id, $weekStart))->toBeNull();
});

it('is one shared instance per request', function (): void {
    expect(app(ResolveWeekAdaptationAction::class))->toBe(app(ResolveWeekAdaptationAction::class));
});
