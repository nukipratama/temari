<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Models\PlanAdaptation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $adaptation = PlanAdaptation::factory()->for($user)->create();

    expect($adaptation->user)->toBeInstanceOf(User::class)
        ->and($adaptation->user->is($user))->toBeTrue();
});

it('casts the week, the reason enum, the flag and both integers', function (): void {
    $adaptation = PlanAdaptation::factory()->make([
        'user_id' => '7',
        'week_start' => '2026-08-10',
        'reason' => 'high_monotony',
        'deload' => 1,
        'quality_delta' => '-1',
        'adherence_pct' => '40',
    ]);

    expect($adaptation->week_start)->toBeInstanceOf(Carbon::class)
        ->and($adaptation->user_id)->toBe(7)
        ->and($adaptation->reason)->toBe(AdaptationReason::HighMonotony)
        ->and($adaptation->deload)->toBeTrue()
        ->and($adaptation->quality_delta)->toBe(-1)
        ->and($adaptation->adherence_pct)->toBe(40);
});

it('serializes week_start as the naive date, not a UTC-shifted instant', function (): void {
    $adaptation = new PlanAdaptation(['week_start' => '2026-08-10']);

    expect($adaptation->toArray()['week_start'])->toBe('2026-08-10');
});

it('enforces one row per user per week', function (): void {
    $user = User::factory()->create();
    PlanAdaptation::factory()->for($user)->create(['week_start' => '2026-08-10']);

    expect(fn () => PlanAdaptation::factory()->for($user)->create(['week_start' => '2026-08-10']))
        ->toThrow(QueryException::class);
});
