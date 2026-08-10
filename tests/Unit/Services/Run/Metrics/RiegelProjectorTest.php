<?php

declare(strict_types=1);

use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Metrics\RiegelProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->projector = new RiegelProjector();
});

it('returns null when the athlete has no PR to anchor a projection from', function (): void {
    $user = User::factory()->create();

    expect($this->projector->project($user, 10_000.0))->toBeNull();
});

it('falls back to the default 1.06 exponent with a wide range for a single PR', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 1_500.0]);

    $result = $this->projector->project($user, 10_000.0);

    expect($result)->not->toBeNull()
        ->and($result['sample_size'])->toBe(1)
        ->and($result['exponent'])->toBe(1.06)
        ->and($result['confidence'])->toBe('low')
        ->and($result['low_sec'])->toBeLessThan($result['predicted_sec'])
        ->and($result['high_sec'])->toBeGreaterThan($result['predicted_sec']);

    // Wide, not falsely precise: the single-PR band is the widest the
    // projector ever produces.
    $halfWidthFraction = ($result['high_sec'] - $result['predicted_sec']) / $result['predicted_sec'];
    expect($halfWidthFraction)->toEqualWithDelta(0.15, 0.001);
});

it('fits an exponent from 3 realistic PRs and projects a plausible marathon time', function (): void {
    $user = User::factory()->create();
    // 5:00/km, 5:12/km, 5:27/km — a realistic fade-with-distance profile.
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 1_500.0]);
    PersonalRecord::factory()->for($user)->create(['category' => '10km', 'value_sec' => 3_120.0]);
    PersonalRecord::factory()->for($user)->create(['category' => 'half_marathon', 'value_sec' => 6_900.0]);

    $result = $this->projector->project($user, 42_195.0);

    expect($result)->not->toBeNull()
        ->and($result['sample_size'])->toBe(3)
        ->and($result['confidence'])->toBe('medium');

    // Plausible range: slower than exactly double the half-marathon time (a
    // real marathon always fades relative to a perfectly even-paced double),
    // but not wildly outside it either — bounded well short of, say, a
    // 6:30/km marathon pace, which this athlete's PRs give no reason to expect.
    expect($result['predicted_sec'])->toBeGreaterThan(6_900.0 * 2)
        ->and($result['predicted_sec'])->toBeLessThan(6_900.0 * 2.3)
        ->and($result['low_sec'])->toBeLessThan($result['predicted_sec'])
        ->and($result['high_sec'])->toBeGreaterThan($result['predicted_sec']);
});

it('narrows the uncertainty band as the PR sample grows', function (): void {
    $thin = User::factory()->create();
    PersonalRecord::factory()->for($thin)->create(['category' => '5km', 'value_sec' => 1_500.0]);
    $thinResult = $this->projector->project($thin, 10_000.0);

    $rich = User::factory()->create();
    PersonalRecord::factory()->for($rich)->create(['category' => '5km', 'value_sec' => 1_500.0]);
    PersonalRecord::factory()->for($rich)->create(['category' => '10km', 'value_sec' => 3_120.0]);
    PersonalRecord::factory()->for($rich)->create(['category' => '15km', 'value_sec' => 4_800.0]);
    PersonalRecord::factory()->for($rich)->create(['category' => 'half_marathon', 'value_sec' => 6_900.0]);
    $richResult = $this->projector->project($rich, 42_195.0);

    $thinFraction = ($thinResult['high_sec'] - $thinResult['predicted_sec']) / $thinResult['predicted_sec'];
    $richFraction = ($richResult['high_sec'] - $richResult['predicted_sec']) / $richResult['predicted_sec'];

    expect($richResult['sample_size'])->toBe(4)
        ->and($richResult['confidence'])->toBe('high')
        ->and($richFraction)->toBeLessThan($thinFraction);
});

it('clamps a pathological 2-point fit instead of extrapolating an implausible exponent', function (): void {
    $steep = User::factory()->create();
    PersonalRecord::factory()->for($steep)->create(['category' => '10km', 'value_sec' => 3_000.0]);
    PersonalRecord::factory()->for($steep)->create(['category' => '15km', 'value_sec' => 6_000.0]);

    expect($this->projector->project($steep, 20_000.0)['exponent'])->toBe(1.3);

    $flat = User::factory()->create();
    PersonalRecord::factory()->for($flat)->create(['category' => '10km', 'value_sec' => 3_000.0]);
    PersonalRecord::factory()->for($flat)->create(['category' => '15km', 'value_sec' => 4_200.0]);

    expect($this->projector->project($flat, 20_000.0)['exponent'])->toBe(0.9);
});

it('converts an effort-window PR (pace, not elapsed time) into a (distance, time) pair', function (): void {
    $user = User::factory()->create();
    // best_5min stores pace sec/km: 240 sec/km over a 300 sec window covers
    // 300 / 240 * 1000 = 1250 m.
    PersonalRecord::factory()->for($user)->create(['category' => 'best_5min', 'value_sec' => 240.0]);

    // Projecting onto that exact derived distance should recover the window's
    // own elapsed time (300s), since the distance ratio is 1 regardless of
    // exponent.
    $result = $this->projector->project($user, 1_250.0);

    expect($result['predicted_sec'])->toEqualWithDelta(300.0, 0.5)
        ->and($result['sample_size'])->toBe(1);
});

it('skips a PR with a zero or negative value_sec rather than dividing by it', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 0.0]);

    expect($this->projector->project($user, 10_000.0))->toBeNull();
});
