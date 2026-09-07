<?php

declare(strict_types=1);

use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->estimator = new VdotEstimator();
});

it('returns null when user has no qualifying distance PR', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => 'best_5min',
        'value_sec' => 300.0,
        'set_at' => Carbon::today()->subMonth(),
    ]);

    expect($this->estimator->estimate($user))->toBeNull();
});

it('computes VDOT from a 5km PR via Daniels', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200.0,
        'set_at' => Carbon::today()->subMonth(),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result)->not->toBeNull()
        ->and($result['source_category'])->toBe('5km')
        ->and($result['vdot'])->toBeFloat()->toBeGreaterThan(45)->toBeLessThan(55);
});

it('picks the PR yielding the lowest VDOT when several exist, so training paces never outrun a real PR', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200.0,
        'set_at' => Carbon::today()->subMonth(),
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => 'half_marathon',
        'value_sec' => 6300.0,
        'set_at' => Carbon::today()->subMonth(),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result['source_category'])->toBe('half_marathon');
});

it('anchors VDOT to the marathon PR rather than a fast short-distance outlier', function (): void {
    $user = User::factory()->create();
    // A fast 1km (anaerobic speed) alongside a much slower marathon PR — the
    // credibility defect this asymmetric-caution rule closes: without it,
    // the 1km PR alone would drive every training pace, including a
    // marathon "target" quicker than any marathon this athlete has ever
    // actually run (the real bug: prescribed 5:39/km vs an actual 8:01/km).
    PersonalRecord::factory()->for($user)->create([
        'category' => '1km',
        'value_sec' => 278.0,
        'set_at' => Carbon::today()->subMonth(),
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => 'marathon',
        'value_sec' => 20_292.0,
        'set_at' => Carbon::today()->subMonth(),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result['source_category'])->toBe('marathon');
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
        'set_at' => Carbon::today()->subMonth(),
    ]);

    expect($this->estimator->estimate($user))->toBeNull();
});

it('returns null for zero or negative inputs', function (): void {
    expect($this->estimator->vdotFromTimeAndDistance(0, 5_000))->toBeNull()
        ->and($this->estimator->vdotFromTimeAndDistance(1_200, 0))->toBeNull();
});

it('drops a PR that has aged out of the window, so current form sets the paces', function (): void {
    $user = User::factory()->create();
    // Today this marathon wins the min-across-categories rule and holds every
    // prescribed pace down to what the athlete could manage two years ago.
    PersonalRecord::factory()->for($user)->create([
        'category' => 'marathon',
        'value_sec' => 20_292.0,
        'set_at' => Carbon::today()->subYears(2),
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => '1km',
        'value_sec' => 278.0,
        'set_at' => Carbon::today()->subMonth(),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result['source_category'])->toBe('1km')
        ->and($result['stale'])->toBeFalse();
});

it('keeps an aged-out PR when nothing newer exists, rather than leaving the athlete with no paces', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => 'marathon',
        'value_sec' => 20_292.0,
        'set_at' => Carbon::today()->subYears(2),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result)->not->toBeNull()
        ->and($result['source_category'])->toBe('marathon')
        ->and($result['stale'])->toBeTrue();
});

it('reports the date of the PR it read, so the number can be shown with its own evidence', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1_200.0,
        'set_at' => Carbon::parse('2026-05-19'),
    ]);

    expect($this->estimator->estimate($user)['set_at']->toDateString())->toBe('2026-05-19');
});

it('measures the window from the given date, so replaying an old week does not judge it by today', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1_200.0,
        'set_at' => Carbon::today()->subMonths(14),
    ]);

    expect($this->estimator->estimate($user)['stale'])->toBeTrue()
        ->and($this->estimator->estimate($user, Carbon::today()->subMonths(13))['stale'])->toBeFalse();
});
