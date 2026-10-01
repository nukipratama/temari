<?php

declare(strict_types=1);

use App\Models\PersonalRecord;
use App\Models\PerformanceEvidence;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\FitnessAnchor;
use App\Models\User;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->estimator = app(VdotEstimator::class);
});

it('anchors fitness to confirmed races and tests with explicit provenance', function (): void {
    $user = User::factory()->create();
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'kind' => 'test', 'distance_m' => 5000, 'elapsed_time_sec' => 1500,
        'performed_on' => Carbon::today()->subWeek(), 'confirmed_at' => now(),
    ]);
    $result = $this->estimator->estimate($user);
    expect($result['confidence'])->toBe('confirmed')->and($result['source_category'])->toBe('confirmed_test')
        ->and($result['stale'])->toBeFalse()->and($result['evidence_id'])->toBeInt();
});

it('does not treat an ordinary easy activity PR as a maximal fitness test', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['workout_type' => 0]);
    PersonalRecord::factory()->for($user)->create([
        'activity_id' => $activity->id, 'category' => '5km', 'value_sec' => 1200, 'set_at' => now(),
    ]);
    $estimate = $this->estimator->estimate($user);
    expect($estimate['confidence'])->toBe('provisional')
        ->and($estimate['evidence_id'])->toBeNull()
        ->and($estimate['quality_vdot'])->toBe($estimate['vdot']);
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

    expect($result['source_category'])->toBe('marathon')
        ->and($result['stale'])->toBeTrue();
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

it('does not use a PR set after the requested as-of date', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1_200.0,
        'set_at' => Carbon::today()->addDay(),
    ]);

    expect($this->estimator->estimate($user, Carbon::today()))->toBeNull();
});

it('anchors quality work on recent short evidence, so intervals are not prescribed slower than a training 5km', function (): void {
    $user = User::factory()->create();
    // The shape found on prod: a hard half four months back and a much fresher
    // 5km. The half is the honest endurance ceiling and must keep easy pace
    // conservative, but letting it also set interval pace prescribed reps
    // SLOWER than the athlete's own sub-maximal 5km.
    PersonalRecord::factory()->for($user)->create([
        'category' => 'half_marathon',
        'value_sec' => 8_845.0,
        'set_at' => Carbon::today()->subMonths(4),
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1_675.0,
        'set_at' => Carbon::today()->subWeek(),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result['vdot'])->toEqualWithDelta(28.5, 0.2)
        ->and($result['quality_vdot'])->toEqualWithDelta(33.6, 0.2)
        ->and($result['quality_source']['source_category'])->toBe('5km');
});

it('leaves the quality anchor equal to the endurance one when the short evidence is just as old', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => 'half_marathon',
        'value_sec' => 8_845.0,
        'set_at' => Carbon::today()->subMonths(4),
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1_675.0,
        'set_at' => Carbon::today()->subMonths(4),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result['quality_vdot'])->toBe($result['vdot'])
        ->and($result['quality_source'])->toBeNull();
});

it('never lets the quality anchor fall below the endurance one', function (): void {
    $user = User::factory()->create();
    // A recent short record that is SLOWER in VDOT terms than the endurance
    // anchor. The quality slice is a subset of the endurance slice, so its
    // minimum can only be higher; this pins that invariant.
    PersonalRecord::factory()->for($user)->create([
        'category' => 'half_marathon',
        'value_sec' => 6_300.0,
        'set_at' => Carbon::today()->subMonth(),
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 2_400.0,
        'set_at' => Carbon::today()->subWeek(),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result['quality_vdot'])->toBeGreaterThanOrEqual($result['vdot']);
});

it('will not let a lone short record establish the quality anchor', function (): void {
    // A 1km "record" is often a closing surge inside an easy run. With nothing
    // sustained beside it there is nothing for the minimum to be conservative
    // against, so quality work falls back to the endurance anchor.
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => 'half_marathon',
        'value_sec' => 8_845.0,
        'set_at' => Carbon::today()->subMonths(4),
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => '1km',
        'value_sec' => 210.0,
        'set_at' => Carbon::today()->subWeek(),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result['quality_vdot'])->toBe($result['vdot'])
        ->and($result['quality_source'])->toBeNull();
});

it('still lets a short record pull the quality anchor down when sustained evidence sits beside it', function (): void {
    // The prod shape: a 5km time trial plus a 1km surge from an easy run. The
    // surge implies the LOWER vdot, so the minimum keeps it — dropping it
    // would make quality paces faster, not safer.
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => 'half_marathon',
        'value_sec' => 8_845.0,
        'set_at' => Carbon::today()->subMonths(4),
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1_675.0,
        'set_at' => Carbon::today()->subWeek(),
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => '1km',
        'value_sec' => 309.0,
        'set_at' => Carbon::today()->subWeeks(2),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result['quality_source']['source_category'])->toBe('1km')
        ->and($result['quality_vdot'])->toEqualWithDelta(31.9, 0.3)
        ->and($result['quality_vdot'])->toBeGreaterThan($result['vdot']);
});

it('does not establish a fitness or quality anchor from incidental efforts under 3km', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '1km', 'value_sec' => 210.0, 'set_at' => Carbon::today()->subWeek(),
    ]);

    expect($this->estimator->estimate($user))->toBeNull();
    $this->estimator->captureProvisionalAnchor($user);
    expect(FitnessAnchor::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('lets a newer confirmed performance replace an older slower result at a comparable distance', function (): void {
    $user = User::factory()->create();
    $older = PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'kind' => 'race', 'distance_m' => 5000, 'elapsed_time_sec' => 1800,
        'performed_on' => Carbon::today()->subMonths(3), 'confirmed_at' => now(),
    ]);
    $newer = PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'kind' => 'test', 'distance_m' => 5200, 'elapsed_time_sec' => 1500,
        'performed_on' => Carbon::today()->subWeek(), 'confirmed_at' => now(),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result['evidence_id'])->toBe($newer->id)
        ->and($result['confidence'])->toBe('confirmed')
        ->and($result['vdot'])->toEqualWithDelta(
            round($this->estimator->vdotFromTimeAndDistance(1500, 5200), 1),
            0.01,
        )
        ->and($result['evidence_id'])->not->toBe($older->id);
});

it('marks materially different confirmed distances as conflicting and keeps the lower VDOT', function (): void {
    $user = User::factory()->create();
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'kind' => 'race', 'distance_m' => 5000, 'elapsed_time_sec' => 1500,
        'performed_on' => Carbon::today()->subWeek(), 'confirmed_at' => now(),
    ]);
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'kind' => 'race', 'distance_m' => 10000, 'elapsed_time_sec' => 4200,
        'performed_on' => Carbon::today()->subDays(2), 'confirmed_at' => now(),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result['confidence'])->toBe('conflicting')
        ->and($result['vdot'])->toEqualWithDelta(
            round($this->estimator->vdotFromTimeAndDistance(4200, 10000), 1),
            0.01,
        );
});

it('excludes an evidence confirmation made after the historical as-of date', function (): void {
    $user = User::factory()->create();
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'kind' => 'test', 'distance_m' => 5000, 'elapsed_time_sec' => 1500,
        'performed_on' => Carbon::parse('2026-09-20'), 'confirmed_at' => Carbon::parse('2026-10-01 12:00:00'),
    ]);

    expect($this->estimator->estimate($user, Carbon::parse('2026-09-30')))->toBeNull();
});

it('does not apply a provisional snapshot captured after a historical as-of date', function (): void {
    $user = User::factory()->create();
    $record = PersonalRecord::factory()->for($user)->create([
        'category' => '5km', 'value_sec' => 1800, 'set_at' => '2026-09-20',
    ]);
    $this->estimator->captureProvisionalAnchor($user, Carbon::parse('2026-10-01 12:00:00'));
    $record->update(['value_sec' => 1500, 'set_at' => '2026-10-01']);

    expect($this->estimator->estimate($user, Carbon::parse('2026-09-30')))->toBeNull();
});

it('keeps old confirmed evidence available with stale confidence', function (): void {
    $user = User::factory()->create();
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'kind' => 'race', 'distance_m' => 5000, 'elapsed_time_sec' => 1500,
        'performed_on' => Carbon::today()->subYears(2), 'confirmed_at' => now(),
    ]);

    $result = $this->estimator->estimate($user);

    expect($result['confidence'])->toBe('stale')
        ->and($result['stale'])->toBeTrue();
});

it('keeps a provisional anchor unchanged when a faster easy activity replaces its only PR', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['workout_type' => 0]);
    $record = PersonalRecord::factory()->for($user)->create([
        'activity_id' => $activity->id, 'category' => '5km', 'value_sec' => 1800, 'set_at' => now(),
    ]);
    $this->estimator->captureProvisionalAnchor($user);
    $before = $this->estimator->estimate($user);
    $beforePaces = app(TrainingPaceCalculator::class)->fromVdotResult($before);

    $record->update(['value_sec' => 1500, 'set_at' => now(), 'activity_id' => Activity::factory()->for($user)->create()->id]);
    $after = $this->estimator->estimate($user);
    $afterPaces = app(TrainingPaceCalculator::class)->fromVdotResult($after);

    expect($after['vdot'])->toBe($before['vdot'])
        ->and($after['quality_vdot'])->toBe($before['quality_vdot'])
        ->and($afterPaces['threshold'])->toBe($beforePaces['threshold'])
        ->and($afterPaces['interval'])->toBe($beforePaces['interval']);
});
