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
