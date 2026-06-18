<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('casts week_ending to a Carbon date and load metrics to floats', function (): void {
    $snap = WeeklySnapshot::factory()->create([
        'week_ending' => '2026-05-10',
        'weekly_trimp' => '459',
        'monotony' => '0.92',
        'strain' => '422.3',
        'runs' => '3',
    ]);

    expect($snap->week_ending)->toBeInstanceOf(Carbon::class)
        ->and($snap->week_ending->toDateString())->toBe('2026-05-10')
        ->and($snap->weekly_trimp)->toBeFloat()
        ->and($snap->monotony)->toBeFloat()->toEqualWithDelta(0.92, 0.001)
        ->and($snap->strain)->toBeFloat()->toEqualWithDelta(422.3, 0.01)
        ->and($snap->runs)->toBe(3);
});

it('serializes week_ending as a plain Y-m-d string under a non-UTC timezone', function (): void {
    $originalTimezone = config('app.timezone');
    $originalPhpTimezone = date_default_timezone_get();
    config(['app.timezone' => 'Asia/Jakarta']);
    date_default_timezone_set('Asia/Jakarta');

    try {
        $snap = WeeklySnapshot::factory()->create(['week_ending' => '2026-06-14']);

        $serialized = $snap->toArray()['week_ending'];

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
    $snap = WeeklySnapshot::factory()->for($user)->create();

    expect($snap->user->is($user))->toBeTrue();
});

it('enforces one snapshot per (user_id, week_ending)', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10']);

    expect(fn () => WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('counts consecutive run-weeks and breaks at the first gap', function (): void {
    $user = User::factory()->create();
    // Adjacent run-weeks, then a gap, then an older adjacent week.
    foreach (['2026-05-24', '2026-05-17', '2026-05-10', '2026-04-26'] as $weekEnding) {
        WeeklySnapshot::factory()->for($user)->create(['week_ending' => $weekEnding, 'runs' => 2]);
    }
    // Zero-run weeks never count, even when adjacent.
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-31', 'runs' => 0]);

    expect(WeeklySnapshot::consecutiveWeekStreak($user->id))->toBe(3);
});

it('returns zero streak when no week has runs', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10', 'runs' => 0]);

    expect(WeeklySnapshot::consecutiveWeekStreak($user->id))->toBe(0);
});
