<?php

declare(strict_types=1);

use Database\Seeders\Demo\HrProfile;
use Database\Seeders\Demo\RunBlueprint;
use Database\Seeders\Demo\StreamSynthesizer;
use Illuminate\Support\Carbon;

function blueprint(int $distanceM, int $paceSecPerKm = 330, HrProfile $hr = HrProfile::Z2Steady): RunBlueprint
{
    return new RunBlueprint(
        startsAt: Carbon::parse('2026-05-01 06:00:00'),
        distanceM: $distanceM,
        targetPaceSecPerKm: $paceSecPerKm,
        hrProfile: $hr,
    );
}

it('produces a final accumulated distance that matches the blueprint exactly', function (): void {
    $synth = new StreamSynthesizer();
    $streams = $synth->build(blueprint(10_000));

    $distance = $streams['distance']['data'];
    $finalDistance = (float) end($distance);

    // Before the fix the rescale walked the rounded velocity series while the
    // scale factor came from the unrounded sum, leaving the final distance
    // drifting off the 10,000 m blueprint. It must now land within rounding
    // noise (each step rounds to 2 dp).
    expect($finalDistance)->toEqualWithDelta(10_000.0, 0.5);
});

it('keeps distance on-target across several blueprint distances and profiles', function (): void {
    $synth = new StreamSynthesizer();

    foreach ([3_000, 5_000, 12_345, 21_098] as $distanceM) {
        foreach ([HrProfile::Z2Steady, HrProfile::Intervals, HrProfile::NegSplit] as $hr) {
            $streams = $synth->build(blueprint($distanceM, hr: $hr));
            $distance = $streams['distance']['data'];

            expect((float) end($distance))
                ->toEqualWithDelta((float) $distanceM, 0.5);
        }
    }
});

it('is deterministic: the same blueprint yields identical streams', function (): void {
    $synth = new StreamSynthesizer();
    $blueprint = blueprint(8_000);

    $first = $synth->build($blueprint);
    $second = $synth->build($blueprint);

    expect($first)->toBe($second);
});

it('keeps the velocity series consistent with the distance series after rescale', function (): void {
    $synth = new StreamSynthesizer();
    $streams = $synth->build(blueprint(10_000));

    $velocity = $streams['velocity_smooth']['data'];
    $distance = $streams['distance']['data'];

    // Re-accumulating the (rounded-to-3dp) velocities should track the stored
    // distance series within accumulated rounding noise: the two series come
    // from the same rescaled source, not divergent ones.
    $acc = 0.0;
    foreach ($velocity as $v) {
        $acc += (float) $v;
    }

    expect($acc)->toEqualWithDelta((float) end($distance), count($velocity) * 0.001 + 0.5);
});

it('omits the heartrate stream entirely when the blueprint has no HR sensor', function (): void {
    $synth = new StreamSynthesizer();
    $blueprint = new RunBlueprint(
        startsAt: Carbon::parse('2026-05-01 06:00:00'),
        distanceM: 5_000,
        targetPaceSecPerKm: 330,
        hrProfile: HrProfile::Z2Steady,
        hasHrSensor: false,
    );

    $streams = $synth->build($blueprint);

    expect($streams)->not->toHaveKey('heartrate');
});

it('omits the cadence stream entirely when the blueprint has no cadence sensor', function (): void {
    $synth = new StreamSynthesizer();
    $blueprint = new RunBlueprint(
        startsAt: Carbon::parse('2026-05-01 06:00:00'),
        distanceM: 5_000,
        targetPaceSecPerKm: 330,
        hrProfile: HrProfile::Z2Steady,
        hasCadenceSensor: false,
    );

    $streams = $synth->build($blueprint);

    expect($streams)->not->toHaveKey('cadence');
});

it('omits the latlng stream entirely when the blueprint has no GPS', function (): void {
    $synth = new StreamSynthesizer();
    $blueprint = new RunBlueprint(
        startsAt: Carbon::parse('2026-05-01 06:00:00'),
        distanceM: 5_000,
        targetPaceSecPerKm: 330,
        hrProfile: HrProfile::Z2Steady,
        hasGps: false,
    );

    $streams = $synth->build($blueprint);

    expect($streams)->not->toHaveKey('latlng');
});

it('falls back to a 1-second duration instead of dividing by zero when moving time is zero', function (): void {
    $synth = new StreamSynthesizer();
    $blueprint = new RunBlueprint(
        startsAt: Carbon::parse('2026-05-01 06:00:00'),
        distanceM: 0,
        targetPaceSecPerKm: 330,
        hrProfile: HrProfile::Z2Steady,
    );

    expect($blueprint->movingTimeSec())->toBe(0);

    $streams = $synth->build($blueprint);

    // duration forced to 1 means exactly 2 samples (t = 0 and t = 1).
    expect($streams['time']['data'])->toBe([0, 1]);
});

it('derives grade_smooth from its own altitude and distance series', function (): void {
    $blueprint = new RunBlueprint(
        startsAt: Carbon::parse('2026-05-01 06:00:00'),
        distanceM: 5000,
        targetPaceSecPerKm: 330,
        hrProfile: HrProfile::Z2Steady,
        elevationGainM: 80,
    );

    $streams = new StreamSynthesizer()->build($blueprint);

    $grade = $streams['grade_smooth']['data'];
    $altitude = $streams['altitude']['data'];
    $distance = $streams['distance']['data'];

    // Same length as every other series, or StreamAnalysis reads them off by one.
    expect($grade)->toHaveCount(count($altitude))
        ->and($grade[0])->toBe(0.0);

    // Each sample is the real rise over the real run, not decoration: without
    // this stream StreamAnalysis computes no max_grade_pct at all, and the
    // vitals card's steepest-grade and flat-pace tiles never render.
    foreach ([1, 100, 500] as $i) {
        $rise = $altitude[$i] - $altitude[$i - 1];
        $run = $distance[$i] - $distance[$i - 1];
        expect($grade[$i])->toBe(round($rise / $run * 100, 2));
    }

    // A run with real elevation has to actually register as climbing.
    expect(max($grade))->toBeGreaterThan(0.0)
        ->and(min($grade))->toBeLessThan(0.0);
});
