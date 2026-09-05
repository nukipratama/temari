<?php

declare(strict_types=1);

use App\Models\TrendDailySnapshot;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('casts snapshot_date to a Carbon date and metrics to floats', function (): void {
    $snap = TrendDailySnapshot::factory()->make([
        'user_id' => 1,
        'snapshot_date' => '2026-08-17',
        'vdot' => '45.6',
        'pace_variability_sec' => '8.3',
    ]);

    expect($snap->snapshot_date)->toBeInstanceOf(Carbon::class)
        ->and($snap->snapshot_date->toDateString())->toBe('2026-08-17')
        ->and($snap->vdot)->toBeFloat()->toEqualWithDelta(45.6, 0.001)
        ->and($snap->pace_variability_sec)->toBeFloat()->toEqualWithDelta(8.3, 0.001);
});

it('serializes snapshot_date as a plain Y-m-d string under a non-UTC timezone', function (): void {
    $originalTimezone = config('app.timezone');
    $originalPhpTimezone = date_default_timezone_get();
    config(['app.timezone' => 'Asia/Jakarta']);
    date_default_timezone_set('Asia/Jakarta');

    try {
        $snap = TrendDailySnapshot::factory()->make(['user_id' => 1, 'snapshot_date' => '2026-06-14']);

        $serialized = $snap->toArray()['snapshot_date'];

        expect($serialized)->toBe('2026-06-14')
            ->and($serialized)->not->toContain('T')
            ->and($serialized)->not->toContain('Z');
    } finally {
        config(['app.timezone' => $originalTimezone]);
        date_default_timezone_set($originalPhpTimezone);
    }
});

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $snap = TrendDailySnapshot::factory()->for($user)->create();

    expect($snap->user->is($user))->toBeTrue();
});

it('enforces one snapshot per (user_id, snapshot_date)', function (): void {
    $user = User::factory()->create();
    TrendDailySnapshot::factory()->for($user)->create(['snapshot_date' => '2026-08-17']);

    expect(fn () => TrendDailySnapshot::factory()->for($user)->create(['snapshot_date' => '2026-08-17']))
        ->toThrow(UniqueConstraintViolationException::class);
});
