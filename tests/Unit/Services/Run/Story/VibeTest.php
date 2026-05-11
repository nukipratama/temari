<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Story\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('returns hibernating when the user has never run', function (): void {
    $user = User::factory()->create();

    expect(app(Vibe::class)->current($user))->toBe(Vibe::HIBERNATING);
});

it('returns hibernating when the last run is 12 days ago', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDays(12),
        'trimp_edwards' => 80.0,
    ]);

    expect(app(Vibe::class)->current($user))->toBe(Vibe::HIBERNATING);
    Carbon::setTestNow();
});

it('returns pumped on a recent PR with non-negative form', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $user = User::factory()->create();

    // 80 days of steady 50 TRIMP/day so CTL catches up to ATL (form ≈ -7,
    // within the ±15 optimal band for CTL in 20-50).
    for ($i = 0; $i < 80; $i++) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => 50.0,
            'start_date_local' => Carbon::today()->subDays(79 - $i),
        ]);
    }
    // Fresh 5K PR 3 days ago.
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1300.0,
        'set_at' => Carbon::today()->subDays(3),
    ]);

    expect(app(Vibe::class)->current($user))->toBe(Vibe::PUMPED);
    Carbon::setTestNow();
});

it('exposes display labels in Indonesian', function (): void {
    expect(Vibe::label(Vibe::BOUNCY))->toBe('Lincah')
        ->and(Vibe::label(Vibe::COOKED))->toBe('Gosong')
        ->and(Vibe::label(Vibe::HIBERNATING))->toBe('Hibernasi')
        ->and(Vibe::emoji(Vibe::PUMPED))->toBe('💥');
});
