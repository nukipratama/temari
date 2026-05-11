<?php

declare(strict_types=1);

use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns null when user has no qualifying distance PR', function (): void {
    $user = User::factory()->create();
    // Only an effort PR — not a real race distance.
    PersonalRecord::factory()->for($user)->create([
        'category' => 'best_5min',
        'value_sec' => 300.0,
    ]);

    expect((new VdotEstimator())->estimate($user))->toBeNull();
});

it('computes VDOT from a 5km PR via Daniels', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200.0, // 20:00 for 5k
    ]);

    $result = (new VdotEstimator())->estimate($user);

    expect($result)->not->toBeNull()
        ->and($result['source_category'])->toBe('5km')
        // A 20:00 5k is roughly VDOT 50 (Daniels' tables agree to ~1 point).
        ->and($result['vdot'])->toBeFloat()->toBeGreaterThan(45)->toBeLessThan(55);
});

it('picks the PR yielding the highest VDOT when several exist', function (): void {
    $user = User::factory()->create();
    // Hypothetical: same person, a sharper 5k than their half-marathon.
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200.0, // 20:00 → VDOT ~50
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => 'half_marathon',
        'value_sec' => 6300.0, // 1:45:00 → lower VDOT
    ]);

    $result = (new VdotEstimator())->estimate($user);

    expect($result['source_category'])->toBe('5km');
});

it('formula computes a believable VDOT for a known marathon time', function (): void {
    // Sub-3-hour marathon (3:00:00 = 10800s) ≈ VDOT 53-55 per Daniels' tables.
    $vdot = (new VdotEstimator())->vdotFromTimeAndDistance(10_800, 42_195);

    expect($vdot)->toBeFloat()->toBeGreaterThan(50)->toBeLessThan(58);
});

it('returns null for zero or negative inputs', function (): void {
    $est = new VdotEstimator();
    expect($est->vdotFromTimeAndDistance(0, 5_000))->toBeNull()
        ->and($est->vdotFromTimeAndDistance(1_200, 0))->toBeNull();
});
