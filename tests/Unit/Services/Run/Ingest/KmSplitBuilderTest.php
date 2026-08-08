<?php

declare(strict_types=1);

use App\Services\Run\Ingest\KmSplitBuilder;

beforeEach(function (): void {
    $this->builder = new KmSplitBuilder();
});

/**
 * A GPS trace running due north from one point. Haversine along a meridian is
 * exactly R·Δlat, so the trace's cumulative distance stays proportional to the
 * metres asked for whatever radius the builder uses — and the scaling to the
 * device's own distance cancels the difference outright.
 *
 * @param  list<int|float>  $metres  cumulative distance at each sample
 * @param  list<int|float>  $seconds  the `time` stream, same indices
 * @param  list<int>  $heartrate
 * @return array{0: list<array{0: float, 1: float}>, 1: list<int|float>, 2: list<int>}
 */
function meridianTrace(array $metres, array $seconds, array $heartrate = []): array
{
    $latlng = [];
    foreach ($metres as $m) {
        $latlng[] = [-6.2 + $m / 111320.0, 106.8];
    }

    return [$latlng, $seconds, $heartrate];
}

/**
 * Watch-style auto-split laps: a 1 km grid with $elapsed one entry per km, then
 * whatever the run finished on.
 *
 * @param  list<int>  $elapsed
 * @return list<array<string, int|float>>
 */
function kmGridLaps(array $elapsed, float $tailDistanceM = 0, int $tailElapsed = 0): array
{
    $laps = [];
    foreach ($elapsed as $i => $sec) {
        $laps[] = ['lap_index' => $i + 1, 'distance' => 1000.0, 'elapsed_time' => $sec, 'moving_time' => $sec];
    }
    if ($tailDistanceM > 0) {
        $laps[] = [
            'lap_index' => count($laps) + 1,
            'distance' => $tailDistanceM,
            'elapsed_time' => $tailElapsed,
            'moving_time' => $tailElapsed,
        ];
    }

    return $laps;
}

// ── Source 1: laps on a clean km grid ──

it('reads a clean km grid straight off the laps, on elapsed time', function (): void {
    // 2026-01-01, 11.25 km. km4 took 8:21 on the wrist; Strava's own
    // moving_time called it 6:55 because auto-pause ate the stopped seconds.
    $laps = kmGridLaps([490, 495, 488, 501, 492, 497, 486, 494, 499, 490, 493], 250.0, 121);
    $splits = [];
    foreach (range(1, 11) as $km) {
        $splits[] = ['split' => $km, 'distance' => 1000.0, 'elapsed_time' => 415, 'moving_time' => 415];
    }

    $rows = $this->builder->perKm($laps, [], [], [], $splits, 11250.0);

    expect($rows)->toHaveCount(11)
        ->and($rows[3])->toBe(['km' => 4, 'pace' => '8:21', 'elapsed_sec' => 501, 'distance_m' => 1000]);
});

it('drops the trailing part-lap from the km rows', function (): void {
    $rows = $this->builder->perKm(kmGridLaps([420, 430], 250.0, 105), [], [], [], null, 2250.0);

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'km'))->toBe([1, 2]);
});

it('carries the lap average heart rate and cadence onto the km row', function (): void {
    $laps = [
        ['distance' => 1000.0, 'elapsed_time' => 420, 'average_heartrate' => 148.4, 'average_cadence' => 82.0],
    ];

    expect($this->builder->perKm($laps, [], [], [], null, 1000.0)[0])
        ->toMatchArray(['avg_hr' => 148, 'avg_cadence_spm' => 164]);
});

// ── The km-grid tolerance ──

it('accepts laps that wobble within 5 m of the grid', function (): void {
    $laps = [
        ['distance' => 1000.0, 'elapsed_time' => 420],
        ['distance' => 997.0, 'elapsed_time' => 400],
        ['distance' => 1003.0, 'elapsed_time' => 440],
        ['distance' => 300.0, 'elapsed_time' => 130],
    ];

    $rows = $this->builder->perKm($laps, [], [], [], null, 3300.0);

    expect($rows)->toHaveCount(3)
        ->and($rows[1])->toMatchArray(['km' => 2, 'distance_m' => 997]);
});

it('rejects the grid when a lap falls more than 5 m short of a kilometre', function (): void {
    $laps = [
        ['distance' => 1000.0, 'elapsed_time' => 420],
        ['distance' => 993.0, 'elapsed_time' => 415],
        ['distance' => 1000.0, 'elapsed_time' => 430],
    ];

    expect($this->builder->perKm($laps, [], [], [], null, 2993.0))->toBe([]);
});

it('rejects the grid when a single lap covers the whole run', function (): void {
    $laps = [['distance' => 5010.0, 'elapsed_time' => 2132]];

    expect($this->builder->perKm($laps, [], [], [], null, 5010.0))->toBe([]);
});

it('ignores laps with no distance or no elapsed time', function (): void {
    $laps = [
        ['distance' => 1000.0, 'elapsed_time' => 420],
        ['distance' => 0.0, 'elapsed_time' => 0],
        ['distance' => 1000.0, 'elapsed_time' => 430],
    ];

    expect($this->builder->perKm($laps, [], [], [], null, 2000.0))->toHaveCount(2);
});

// ── Source 2: the GPS trace ──

it('falls back to the GPS trace when manual laps break the km grid', function (): void {
    // 2026-08-06, 5.01 km, six laps: the fifth is 647 m, so the grid is broken
    // and the trace decides. Wrist: 7:17, 7:19, 7:04, 7:22, 6:26.
    $laps = [
        ['distance' => 1000.0, 'elapsed_time' => 437],
        ['distance' => 1000.0, 'elapsed_time' => 439],
        ['distance' => 1000.0, 'elapsed_time' => 424],
        ['distance' => 1005.0, 'elapsed_time' => 444],
        ['distance' => 647.0, 'elapsed_time' => 250],
        ['distance' => 358.0, 'elapsed_time' => 138],
    ];
    [$latlng, $time, $heartrate] = meridianTrace(
        [0, 500, 1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500, 5000, 5010],
        [0, 210, 437, 660, 876, 1090, 1300, 1520, 1742, 1930, 2128, 2132],
    );

    $rows = $this->builder->perKm($laps, $latlng, $time, $heartrate, null, 5010.0);

    expect(array_column($rows, 'pace'))->toBe(['7:17', '7:19', '7:04', '7:22', '6:26'])
        ->and(array_column($rows, 'elapsed_sec'))->toBe([437, 439, 424, 442, 386])
        ->and($rows[0]['distance_m'])->toBe(1000);
});

it('scales the trace onto the distance the device itself reported', function (): void {
    // The trace measures 2000 m; the device says 2200. The first kilometre is
    // therefore reached at 909 m of trace, not at 1000.
    $metres = range(0, 2000, 100);
    $seconds = array_map(fn (int $m): float => $m / 2.5, $metres);

    [$latlng, $time, $heartrate] = meridianTrace($metres, $seconds);
    $rows = $this->builder->perKm(null, $latlng, $time, $heartrate, null, 2200.0);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['elapsed_sec'])->toBe(364);
});

it('interpolates a km boundary that lands between two samples', function (): void {
    // 60 m of trace per sample, so 1000 m falls two thirds of the way through
    // the seventeenth: 416.7 s. Snapping to either neighbour would miss by ~8 s.
    $metres = range(0, 1200, 60);
    $seconds = array_map(fn (int $m): float => $m / 2.4, $metres);

    [$latlng, $time, $heartrate] = meridianTrace($metres, $seconds);
    $rows = $this->builder->perKm(null, $latlng, $time, $heartrate, null, 1200.0);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['elapsed_sec'])->toEqualWithDelta(417, 2);
});

it('averages the heart-rate stream across each kilometre of the trace', function (): void {
    $metres = range(0, 2000, 100);
    $seconds = array_map(fn (int $m): float => $m / 2.5, $metres);
    $sampledHeartrate = array_merge(array_fill(0, 10, 150), array_fill(0, 11, 160));

    [$latlng, $time, $heartrate] = meridianTrace($metres, $seconds, $sampledHeartrate);
    $rows = $this->builder->perKm(null, $latlng, $time, $heartrate, null, 2000.0);

    expect($rows[0]['avg_hr'])->toBe(150)
        ->and($rows[1]['avg_hr'])->toBe(160);
});

it('drops a km boundary collapsed to zero elapsed time by a duplicate-timestamp GPS jump', function (): void {
    // Two samples share one watch-clock second across a 2000 m jump, so both km
    // boundaries interpolate to the same instant. km2 is dropped rather than
    // reported as a kilometre run in no time at all.
    [$latlng, $time, $heartrate] = meridianTrace([0, 500, 2500, 2600], [0, 50, 50, 150]);

    $rows = $this->builder->perKm(null, $latlng, $time, $heartrate, null, 2600.0);

    expect(array_column($rows, 'km'))->toBe([1])
        ->and($rows[0]['elapsed_sec'])->toBe(50);
});

it('ignores a trace too short or too still to measure', function (): void {
    [$latlngShort, $timeShort, $heartrateShort] = meridianTrace([0], [0]);
    [$latlngStill, $timeStill, $heartrateStill] = meridianTrace([0, 0, 0], [0, 60, 120]);

    expect($this->builder->perKm(null, $latlngShort, $timeShort, $heartrateShort, null, 1000.0))->toBe([])
        ->and($this->builder->perKm(null, $latlngStill, $timeStill, $heartrateStill, null, 1000.0))->toBe([]);
});

// ── Source 3: splits_metric ──

it('falls back to splits_metric on elapsed time when there is no GPS trace', function (): void {
    // Treadmill: no laps, no latlng. moving_time would have said 6:55.
    $splits = [
        ['split' => 1, 'distance' => 1000.0, 'elapsed_time' => 480, 'moving_time' => 470, 'average_heartrate' => 145],
        ['split' => 2, 'distance' => 1000.0, 'elapsed_time' => 501, 'moving_time' => 415, 'average_cadence' => 80],
        ['split' => 3, 'distance' => 640.0, 'elapsed_time' => 300],
    ];

    $rows = $this->builder->perKm(null, [], [], [], $splits, 2640.0);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['km' => 1, 'pace' => '8:00', 'elapsed_sec' => 480, 'distance_m' => 1000, 'avg_hr' => 145])
        ->and($rows[1])->toMatchArray(['km' => 2, 'pace' => '8:21', 'avg_cadence_spm' => 160]);
});

it('returns nothing when no source can describe a kilometre', function (): void {
    expect($this->builder->perKm(null, [], [], [], null, null))->toBe([])
        ->and($this->builder->perKm([], [], [], [], [], 0.0))->toBe([]);
});

// ── Lap rows ──

it('normalizes the laps as their own rows, at whatever length they were', function (): void {
    // 2026-08-06: six laps, the last two 647 m and 358 m.
    $laps = [
        ['distance' => 1000.0, 'elapsed_time' => 437, 'average_heartrate' => 152.0],
        ['distance' => 1000.0, 'elapsed_time' => 439],
        ['distance' => 1000.0, 'elapsed_time' => 424],
        ['distance' => 1005.0, 'elapsed_time' => 444],
        ['distance' => 647.0, 'elapsed_time' => 250],
        ['distance' => 358.0, 'elapsed_time' => 138],
    ];

    $rows = $this->builder->laps($laps);

    expect($rows)->toHaveCount(6)
        ->and(array_column($rows, 'distance_m'))->toBe([1000, 1000, 1000, 1005, 647, 358])
        ->and($rows[0])->toBe([
            'lap' => 1,
            'distance_m' => 1000,
            'elapsed_sec' => 437,
            'pace' => '7:17',
            'avg_hr' => 152,
        ])
        ->and($rows[4]['pace'])->toBe('6:26');
});

it('has no lap rows to report when the activity carries none', function (): void {
    expect($this->builder->laps(null))->toBe([])
        ->and($this->builder->laps([]))->toBe([]);
});
