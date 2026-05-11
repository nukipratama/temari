<?php

declare(strict_types=1);

use App\Services\Run\Ingest\StreamAnalysis;

function defaultZones(): array
{
    return [
        'Z1' => ['lo' => 117, 'hi' => 140],
        'Z2' => ['lo' => 140, 'hi' => 156],
        'Z3' => ['lo' => 156, 'hi' => 170],
        'Z4' => ['lo' => 170, 'hi' => 179],
        'Z5' => ['lo' => 179, 'hi' => 999],
    ];
}

it('classifies seconds by HR zone and returns min + pct', function (): void {
    // 600s total at 4 distinct HR levels:
    //   0-180s @ 130 (Z1: 180s = 3min)
    //   180-360s @ 148 (Z2: 180s = 3min)
    //   360-540s @ 162 (Z3: 180s = 3min)
    //   540-600s @ 175 (Z4: 60s = 1min)
    $time = [];
    $hr = [];
    for ($t = 0; $t <= 600; $t += 60) {
        $time[] = $t;
        $hr[] = match (true) {
            $t < 180 => 130,
            $t < 360 => 148,
            $t < 540 => 162,
            default => 175,
        };
    }

    $summary = (new StreamAnalysis())->compute(
        ['time' => ['data' => $time], 'heartrate' => ['data' => $hr]],
        defaultZones(),
        null,
        170,
    );

    expect($summary['time_in_zone_min'])->toBe([
        'Z1' => 3.0, 'Z2' => 3.0, 'Z3' => 3.0, 'Z4' => 1.0, 'Z5' => 0.0,
    ]);
});

it('returns no zone summary when streams are missing HR', function (): void {
    $summary = (new StreamAnalysis())->compute(
        ['time' => ['data' => [0, 60, 120]]],
        defaultZones(),
        null,
        170,
    );

    expect($summary)->not->toHaveKey('time_in_zone_min');
});

it('detects a stop when velocity drops below 0.5 m/s', function (): void {
    $time = [0, 60, 120, 180, 240, 300];
    $velocity = [2.5, 2.5, 0.0, 0.0, 2.5, 2.5]; // 120s stopped between t=120 and t=240

    $summary = (new StreamAnalysis())->compute(
        ['time' => ['data' => $time], 'velocity_smooth' => ['data' => $velocity]],
        defaultZones(),
        null,
        170,
    );

    expect($summary)->toMatchArray([
        'stopped_time_sec' => 120,
        'stop_count' => 1,
    ]);
});

it('computes cardiac decoupling as (second-half HR/pace ratio) - 1', function (): void {
    // First half: HR 140 at 2.5 m/s (= 400s/km). Ratio 140/400 = 0.35.
    // Second half: HR 150 at 2.5 m/s. Ratio 150/400 = 0.375. Drift +7.1%.
    $time = [];
    $hr = [];
    $velocity = [];
    for ($t = 0; $t <= 600; $t += 30) {
        $time[] = $t;
        $hr[] = $t < 300 ? 140 : 150;
        $velocity[] = 2.5;
    }

    $summary = (new StreamAnalysis())->compute(
        [
            'time' => ['data' => $time],
            'heartrate' => ['data' => $hr],
            'velocity_smooth' => ['data' => $velocity],
        ],
        defaultZones(),
        null,
        170,
    );

    expect($summary['decoupling_pct'])->toBeFloat()
        ->toEqualWithDelta(7.1, 0.2);
});

it('finds the best 5-min pace as fastest sustained window', function (): void {
    // 30 min run mostly at 2.5 m/s (6:40/km) with a 6-minute fast block at 3.5 m/s (4:46/km)
    $time = [];
    $velocity = [];
    for ($t = 0; $t <= 1800; $t += 30) {
        $time[] = $t;
        $velocity[] = ($t >= 600 && $t <= 960) ? 3.5 : 2.5;
    }

    $pace = (new StreamAnalysis())->bestEffortPace($time, $velocity, 300);

    // Best 5-min should sit inside the 3.5 m/s block (4:46/km), not the slow base.
    expect($pace)->toBeString()->toBe('4:46');
});

it('returns null best-effort pace when the run is too short', function (): void {
    expect((new StreamAnalysis())->bestEffortPace([0, 30, 60], [2.5, 2.5, 2.5], 300))->toBeNull();
});

it('computes per-km splits + negative_split + hr drift + cadence drop from splits', function (): void {
    $splits = [
        ['split' => 1, 'distance' => 1000, 'elapsed_time' => 420, 'average_speed' => 2.38, 'average_heartrate' => 140, 'average_cadence' => 82],
        ['split' => 2, 'distance' => 1000, 'elapsed_time' => 410, 'average_speed' => 2.44, 'average_heartrate' => 145, 'average_cadence' => 82],
        ['split' => 3, 'distance' => 1000, 'elapsed_time' => 400, 'average_speed' => 2.50, 'average_heartrate' => 150, 'average_cadence' => 80],
        ['split' => 4, 'distance' => 1000, 'elapsed_time' => 390, 'average_speed' => 2.56, 'average_heartrate' => 155, 'average_cadence' => 80],
    ];

    $summary = (new StreamAnalysis())->compute([], defaultZones(), $splits, 170);

    expect($summary['per_km'])->toHaveCount(4)
        ->and($summary['per_km'][0])->toMatchArray(['km' => 1, 'pace' => '7:00', 'avg_hr' => 140])
        ->and($summary['negative_split'])->toBeTrue() // pace got faster
        ->and($summary['hr_drift_bpm'])->toBeFloat()->toEqualWithDelta(15.0, 0.01)
        ->and($summary['cadence_drop_spm'])->toBeFloat()->toEqualWithDelta(4.0, 0.01);
});

it('classifies cadence into <165, 165-175, >175 buckets and optimal pct', function (): void {
    $time = [0, 60, 120, 180, 240, 300, 360, 420];
    // cadence stream is RPM per foot → ×2 for SPM
    // SPM 160 / 160 / 170 / 170 / 180 / 180 / 178
    $cadence = [80, 80, 85, 85, 90, 90, 89];

    $summary = (new StreamAnalysis())->compute(
        ['time' => ['data' => $time], 'cadence' => ['data' => $cadence]],
        defaultZones(),
        null,
        170,
    );

    expect($summary['cadence_distribution_pct'])
        ->toMatchArray([
            '<165' => 28.6, // 120s of 420s
            '165-175' => 28.6,
            '>175' => 42.9,
        ])
        ->and($summary['optimal_cadence_pct'])->toBeFloat(); // SPM in 170..185 range
});

it('returns null best-effort pace when velocity array is shorter than time', function (): void {
    expect((new StreamAnalysis())->bestEffortPace([0, 30, 60, 90, 120, 150, 180, 210, 240, 270, 300], [2.5, 2.5], 300))
        ->toBeNull();
});

it('returns null best-effort pace when velocity is all zero (no distance covered)', function (): void {
    $time = range(0, 600, 30);
    $velocity = array_fill(0, count($time), 0.0);

    expect((new StreamAnalysis())->bestEffortPace($time, $velocity, 300))->toBeNull();
});

it('skips zone summary when no second matches any zone band', function (): void {
    // HR values all below Z1 lower bound → total in-zone seconds = 0.
    $time = [0, 60, 120, 180];
    $hr = [80, 80, 80, 80];
    $summary = (new StreamAnalysis())->compute(
        ['time' => ['data' => $time], 'heartrate' => ['data' => $hr]],
        defaultZones(),
        null,
        170,
    );

    expect($summary)->not->toHaveKey('time_in_zone_min');
});

it('omits decoupling when every sample is stopped (no moving seconds)', function (): void {
    $time = range(0, 600, 60);
    $hr = array_fill(0, count($time), 150);
    $velocity = array_fill(0, count($time), 0.0); // all stopped

    $summary = (new StreamAnalysis())->compute(
        [
            'time' => ['data' => $time],
            'heartrate' => ['data' => $hr],
            'velocity_smooth' => ['data' => $velocity],
        ],
        defaultZones(),
        null,
        170,
    );

    expect($summary)->not->toHaveKey('decoupling_pct');
});

it('omits decoupling when only one half has moving samples', function (): void {
    // First half stopped, second half moving: avg helper returns null for half 1.
    $time = range(0, 600, 60);
    $hr = array_fill(0, count($time), 150);
    $velocity = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 2.5, 2.5, 2.5, 2.5, 2.5];

    $summary = (new StreamAnalysis())->compute(
        [
            'time' => ['data' => $time],
            'heartrate' => ['data' => $hr],
            'velocity_smooth' => ['data' => $velocity],
        ],
        defaultZones(),
        null,
        170,
    );

    expect($summary)->not->toHaveKey('decoupling_pct');
});

it('omits cadence summary when all dt are zero (degenerate time array)', function (): void {
    $time = [0, 0, 0, 0];
    $cadence = [85, 85, 85, 85];

    $summary = (new StreamAnalysis())->compute(
        ['time' => ['data' => $time], 'cadence' => ['data' => $cadence]],
        defaultZones(),
        null,
        170,
    );

    expect($summary)->not->toHaveKey('cadence_distribution_pct');
});

it('integrates altitude into ascent + descent meters', function (): void {
    $altitude = [100, 105, 110, 108, 115, 113];
    $summary = (new StreamAnalysis())->compute(
        ['altitude' => ['data' => $altitude]],
        defaultZones(),
        null,
        170,
    );

    expect($summary)->toMatchArray([
        'ascent_m' => 17, // +5, +5, +7
        'descent_m' => 4, // -2, -2
    ]);
});
