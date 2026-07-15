<?php

declare(strict_types=1);

use App\Services\Run\Ingest\StreamAnalysis;

beforeEach(function (): void {
    $this->analysis = new StreamAnalysis();
});

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
    // 600s split across 4 HR bands: 180s @130(Z1), 180s @148(Z2), 180s @162(Z3), 60s @175(Z4).
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

    $summary = $this->analysis->compute(
        ['time' => ['data' => $time], 'heartrate' => ['data' => $hr]],
        defaultZones(),
        null,
        170,
    );

    expect($summary['time_in_zone_min'])->toBe([
        'Z1' => 3.0, 'Z2' => 3.0, 'Z3' => 3.0, 'Z4' => 1.0, 'Z5' => 0.0,
    ]);
});

it('has gapless HR zones in config so every boundary bpm lands in exactly one zone', function (): void {
    /** @var array<string, array{lo: int, hi: int}> $zones */
    $zones = config('runner.hr_zones');
    $ordered = array_values($zones);

    // Each zone's hi equals the next zone's lo: no gaps, no overlaps.
    for ($i = 0; $i < count($ordered) - 1; $i++) {
        expect($ordered[$i]['hi'])->toBe($ordered[$i + 1]['lo']);
    }

    // Sweep every boundary bpm (each zone's lo) and assert it matches exactly
    // one zone under the inclusive-lo / exclusive-hi rule.
    foreach ($zones as $range) {
        $bpm = $range['lo'];
        $matches = 0;
        foreach ($zones as $z) {
            if ($bpm >= $z['lo'] && $bpm < $z['hi']) {
                $matches++;
            }
        }
        expect($matches)->toBe(1, "bpm {$bpm} should match exactly one zone");
    }
});

it('counts a boundary bpm second into a zone using the real config zones', function (): void {
    // 138 bpm is the Z1.hi / Z2.lo boundary. Under the gapless config it must
    // land in Z2 and contribute its 60s; the old +1-gap config dropped it.
    /** @var array<string, array{lo: int, hi: int}> $zones */
    $zones = config('runner.hr_zones');

    $summary = $this->analysis->compute(
        ['time' => ['data' => [0, 60, 120]], 'heartrate' => ['data' => [138, 138]]],
        $zones,
        null,
        170,
    );

    expect($summary['time_in_zone_min']['Z2'])->toBe(2.0)
        ->and($summary['time_in_zone_pct']['Z2'])->toBe(100.0);
});

it('returns no zone summary when streams are missing HR', function (): void {
    $summary = $this->analysis->compute(
        ['time' => ['data' => [0, 60, 120]]],
        defaultZones(),
        null,
        170,
    );

    expect($summary)->not->toHaveKey('time_in_zone_min');
});

it('detects a stop when velocity drops below 0.5 m/s', function (): void {
    $time = [0, 60, 120, 180, 240, 300];
    // 120s stopped between t=120 and t=240.
    $velocity = [2.5, 2.5, 0.0, 0.0, 2.5, 2.5];

    $summary = $this->analysis->compute(
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
    // Half1: 140bpm/400s/km = 0.35. Half2: 150bpm/400s/km = 0.375. Drift +7.1%.
    $time = [];
    $hr = [];
    $velocity = [];
    for ($t = 0; $t <= 600; $t += 30) {
        $time[] = $t;
        $hr[] = $t < 300 ? 140 : 150;
        $velocity[] = 2.5;
    }

    $summary = $this->analysis->compute(
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
    // Base 2.5 m/s (6:40/km), 6-min block at 3.5 m/s (4:46/km) midway.
    $time = [];
    $velocity = [];
    for ($t = 0; $t <= 1800; $t += 30) {
        $time[] = $t;
        $velocity[] = ($t >= 600 && $t <= 960) ? 3.5 : 2.5;
    }

    $pace = $this->analysis->bestEffortPace($time, $velocity, 300);

    expect($pace)->toBeString()->toBe('4:46');
});

it('returns null best-effort pace when the run is too short', function (): void {
    expect($this->analysis->bestEffortPace([0, 30, 60], [2.5, 2.5, 2.5], 300))->toBeNull();
});

it('keeps best-effort pace at the true rate for a steady run (no window inflation)', function (): void {
    // Constant 3.0 m/s (= 333.3 s/km = 5:33/km) for 20 min. The best 5-min
    // window must report the steady pace, not an inflated one from a window
    // whose distance slightly overshoots the target.
    $time = [];
    $velocity = [];
    for ($t = 0; $t <= 1200; $t += 60) {
        $time[] = $t;
        $velocity[] = 3.0;
    }

    expect($this->analysis->bestEffortPace($time, $velocity, 300))->toBe('5:33');
});

it('keeps best-effort pace true on sparse 120s sampling (no window overshoot inflation)', function (): void {
    // Constant 3.0 m/s (= 5:33/km) sampled every 120s. With a 300s target each
    // window spans 360s and overshoots by a whole 120s segment; crediting the
    // full distance to 300s would report ~4:38. The trailing-edge trim must
    // bring it back to the true 5:33.
    $time = [];
    $velocity = [];
    for ($t = 0; $t <= 1200; $t += 120) {
        $time[] = $t;
        $velocity[] = 3.0;
    }

    expect($this->analysis->bestEffortPace($time, $velocity, 300))->toBe('5:33');
});

it('keeps best-effort pace true across an 11s mid-run gap (auto-pause)', function (): void {
    // Constant 3.0 m/s (= 5:33/km), 1 Hz, with one 11s time jump mid-run
    // (a Strava auto-pause gap). The overshooting trailing segment is trimmed
    // so the reported pace stays 5:33.
    $time = [];
    $velocity = [];
    $cursor = 0;
    for ($k = 0; $k < 150; $k++) {
        $time[] = $cursor;
        $velocity[] = 3.0;
        $cursor += 1;
    }
    $time[] = $cursor;
    $velocity[] = 3.0;
    $cursor += 11;
    for ($k = 0; $k < 200; $k++) {
        $time[] = $cursor;
        $velocity[] = 3.0;
        $cursor += 1;
    }

    expect($this->analysis->bestEffortPace($time, $velocity, 300))->toBe('5:33');
});

it('emits the best_30min_pace window from compute()', function (): void {
    // Steady 3.0 m/s for >30min so the 1800s window qualifies. The key name
    // must match what PrCategory::Best30Min and ThresholdEstimator read.
    $time = [];
    $velocity = [];
    for ($t = 0; $t <= 2000; $t += 10) {
        $time[] = $t;
        $velocity[] = 3.0;
    }

    $summary = $this->analysis->compute(
        ['time' => ['data' => $time], 'velocity_smooth' => ['data' => $velocity]],
        defaultZones(),
        null,
        170,
    );

    expect($summary)->toHaveKey('best_30min_pace')
        ->and($summary['best_30min_pace'])->toBe('5:33');
});

it('computes per-km splits + negative_split + hr drift + cadence drop from splits', function (): void {
    $splits = [
        ['split' => 1, 'distance' => 1000, 'elapsed_time' => 420, 'average_speed' => 2.38, 'average_heartrate' => 140, 'average_cadence' => 82],
        ['split' => 2, 'distance' => 1000, 'elapsed_time' => 410, 'average_speed' => 2.44, 'average_heartrate' => 145, 'average_cadence' => 82],
        ['split' => 3, 'distance' => 1000, 'elapsed_time' => 400, 'average_speed' => 2.50, 'average_heartrate' => 150, 'average_cadence' => 80],
        ['split' => 4, 'distance' => 1000, 'elapsed_time' => 390, 'average_speed' => 2.56, 'average_heartrate' => 155, 'average_cadence' => 80],
    ];

    $summary = $this->analysis->compute([], defaultZones(), $splits, 170);

    expect($summary['per_km'])->toHaveCount(4)
        ->and($summary['per_km'][0])->toMatchArray(['km' => 1, 'pace' => '7:00', 'avg_hr' => 140])
        ->and($summary['negative_split'])->toBeTrue()
        ->and($summary['hr_drift_bpm'])->toBeFloat()->toEqualWithDelta(15.0, 0.01)
        ->and($summary['cadence_drop_spm'])->toBeFloat()->toEqualWithDelta(4.0, 0.01);
});

it('classifies cadence into <165, 165-175, >175 buckets and optimal pct', function (): void {
    $time = [0, 60, 120, 180, 240, 300, 360, 420];
    // Cadence stream is RPM per foot; ×2 → SPM 160/160/170/170/180/180/178.
    $cadence = [80, 80, 85, 85, 90, 90, 89];

    $summary = $this->analysis->compute(
        ['time' => ['data' => $time], 'cadence' => ['data' => $cadence]],
        defaultZones(),
        null,
        170,
    );

    expect($summary['cadence_distribution_pct'])
        ->toMatchArray([
            '<165' => 28.6,
            '165-175' => 28.6,
            '>175' => 42.9,
        ])
        ->and($summary['optimal_cadence_pct'])->toBeFloat();
});

it('returns null best-effort pace when velocity array is shorter than time', function (): void {
    expect($this->analysis->bestEffortPace([0, 30, 60, 90, 120, 150, 180, 210, 240, 270, 300], [2.5, 2.5], 300))
        ->toBeNull();
});

it('returns null best-effort pace when velocity is all zero (no distance covered)', function (): void {
    $time = range(0, 600, 30);
    $velocity = array_fill(0, count($time), 0.0);

    expect($this->analysis->bestEffortPace($time, $velocity, 300))->toBeNull();
});

it('skips zone summary when no second matches any zone band', function (): void {
    $time = [0, 60, 120, 180];
    $hr = [80, 80, 80, 80];
    $summary = $this->analysis->compute(
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
    $velocity = array_fill(0, count($time), 0.0);

    $summary = $this->analysis->compute(
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
    $time = range(0, 600, 60);
    $hr = array_fill(0, count($time), 150);
    $velocity = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 2.5, 2.5, 2.5, 2.5, 2.5];

    $summary = $this->analysis->compute(
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

    $summary = $this->analysis->compute(
        ['time' => ['data' => $time], 'cadence' => ['data' => $cadence]],
        defaultZones(),
        null,
        170,
    );

    expect($summary)->not->toHaveKey('cadence_distribution_pct');
});

it('integrates altitude into ascent + descent meters', function (): void {
    $altitude = [100, 105, 110, 108, 115, 113];
    $summary = $this->analysis->compute(
        ['altitude' => ['data' => $altitude]],
        defaultZones(),
        null,
        170,
    );

    expect($summary)->toMatchArray([
        'ascent_m' => 17,
        'descent_m' => 4,
    ]);
});

/**
 * 1 Hz 3 km dataset @ 6:00/km. Cadence per-leg: km1=85, km2=88, km3=80 (×2 → 170/176/160).
 *
 * @return array{0: array<string, array{data: list<int|float>}>, 1: list<array<string, mixed>>}
 */
function buildCadenceTestRun(): array
{
    $duration = 1080;
    $speedMs = 1000.0 / 360.0;
    $time = [];
    $distance = [];
    $cadence = [];
    for ($t = 0; $t <= $duration; $t++) {
        $time[] = $t;
        $distance[] = round($t * $speedMs, 2);
        $km = (int) floor(($t * $speedMs) / 1000) + 1;
        $cadence[] = match ($km) {
            1 => 85,
            2 => 88,
            default => 80,
        };
    }

    $splits = [
        ['split' => 1, 'distance' => 1000.0, 'elapsed_time' => 360, 'moving_time' => 360, 'average_speed' => 2.778, 'average_heartrate' => 150],
        ['split' => 2, 'distance' => 1000.0, 'elapsed_time' => 360, 'moving_time' => 360, 'average_speed' => 2.778, 'average_heartrate' => 152],
        ['split' => 3, 'distance' => 1000.0, 'elapsed_time' => 360, 'moving_time' => 360, 'average_speed' => 2.778, 'average_heartrate' => 154],
    ];

    return [[
        'time' => ['data' => $time],
        'distance' => ['data' => $distance],
        'cadence' => ['data' => $cadence],
        'velocity_smooth' => ['data' => array_fill(0, $duration + 1, $speedMs)],
    ], $splits];
}

it('populates per-km avg_cadence_spm from the cadence stream + distance stream', function (): void {
    [$streams, $splits] = buildCadenceTestRun();

    $summary = $this->analysis->compute($streams, defaultZones(), $splits, 170);

    expect($summary['per_km'])->toHaveCount(3);
    expect($summary['per_km'][0]['avg_cadence_spm'])->toBe(170)
        ->and($summary['per_km'][1]['avg_cadence_spm'])->toBe(176)
        ->and($summary['per_km'][2]['avg_cadence_spm'])->toBe(160);
});

it('skips per-km cadence when the distance stream is empty', function (): void {
    [$streams, $splits] = buildCadenceTestRun();
    unset($streams['distance']);

    $summary = $this->analysis->compute($streams, defaultZones(), $splits, 170);

    expect($summary['per_km'])->toHaveCount(3);
    expect($summary['per_km'][0])->not->toHaveKey('avg_cadence_spm');
});

it('omits avg_cadence_spm for km buckets with no cadence samples', function (): void {
    [$streams, $splits] = buildCadenceTestRun();
    // Cut the last third → km 3 bucket stays empty.
    $streams['cadence']['data'] = array_slice($streams['cadence']['data'], 0, 720);

    $summary = $this->analysis->compute($streams, defaultZones(), $splits, 170);

    expect($summary['per_km'][0])->toHaveKey('avg_cadence_spm')
        ->and($summary['per_km'][1])->toHaveKey('avg_cadence_spm')
        ->and($summary['per_km'][2])->not->toHaveKey('avg_cadence_spm');
});

it('skips per-km cadence when time stream has only one sample', function (): void {
    [$streams, $splits] = buildCadenceTestRun();
    $streams['time']['data'] = [0];
    $streams['distance']['data'] = [0.0];
    $streams['cadence']['data'] = [85];

    $summary = $this->analysis->compute($streams, defaultZones(), $splits, 170);

    expect($summary['per_km'][0])->not->toHaveKey('avg_cadence_spm');
});

it('ignores cadence samples where dt <= 0 (degenerate time array)', function (): void {
    [$streams, $splits] = buildCadenceTestRun();
    $streams['time']['data'] = array_fill(0, 720, 0);
    $streams['distance']['data'] = array_slice($streams['distance']['data'], 0, 720);
    $streams['cadence']['data'] = array_slice($streams['cadence']['data'], 0, 720);

    $summary = $this->analysis->compute($streams, defaultZones(), $splits, 170);

    expect($summary['per_km'][0])->not->toHaveKey('avg_cadence_spm')
        ->and($summary['per_km'][1])->not->toHaveKey('avg_cadence_spm');
});

it('preserves a pre-existing avg_cadence_spm from splits_metric', function (): void {
    // Splits value wins over the stream-walker fallback.
    [$streams, $splits] = buildCadenceTestRun();
    $splits[0]['average_cadence'] = 100;

    $summary = $this->analysis->compute($streams, defaultZones(), $splits, 170);

    expect($summary['per_km'][0]['avg_cadence_spm'])->toBe(200)
        ->and($summary['per_km'][1]['avg_cadence_spm'])->toBe(176);
});
