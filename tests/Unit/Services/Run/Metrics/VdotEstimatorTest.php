<?php

declare(strict_types=1);

use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->estimator = new VdotEstimator();
});

it('returns null when user has no qualifying distance PR', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => 'best_5min',
        'value_sec' => 300.0,
    ]);

    expect($this->estimator->estimate($user))->toBeNull();
});

it('computes VDOT from a 5km PR via Daniels', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200.0,
    ]);

    $result = $this->estimator->estimate($user);

    expect($result)->not->toBeNull()
        ->and($result['source_category'])->toBe('5km')
        ->and($result['vdot'])->toBeFloat()->toBeGreaterThan(45)->toBeLessThan(55);
});

it('picks the PR yielding the highest VDOT when several exist', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200.0,
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => 'half_marathon',
        'value_sec' => 6300.0,
    ]);

    $result = $this->estimator->estimate($user);

    expect($result['source_category'])->toBe('5km');
});

it('formula computes a believable VDOT for a known marathon time', function (): void {
    // Sub-3-hour marathon ≈ VDOT 53-55 per Daniels' tables.
    $vdot = $this->estimator->vdotFromTimeAndDistance(10_800, 42_195);

    expect($vdot)->toBeFloat()->toBeGreaterThan(50)->toBeLessThan(58);
});

it('skips PRs whose value yields a non-positive VO2 (impossibly slow time)', function (): void {
    $user = User::factory()->create();
    // ~5h for 5km lands the Daniels VO2 polynomial in negative territory.
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 30_000.0,
    ]);

    expect($this->estimator->estimate($user))->toBeNull();
});

it('returns null for zero or negative inputs', function (): void {
    expect($this->estimator->vdotFromTimeAndDistance(0, 5_000))->toBeNull()
        ->and($this->estimator->vdotFromTimeAndDistance(1_200, 0))->toBeNull();
});
