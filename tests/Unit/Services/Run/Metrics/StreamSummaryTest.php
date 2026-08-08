<?php

declare(strict_types=1);

use App\Services\Run\Metrics\StreamSummary;

it('reads the time-in-zone percent breakdown', function (): void {
    $summary = StreamSummary::fromArray(['time_in_zone_pct' => ['Z1' => 10, 'Z2' => 60, 'Z3' => 30]]);

    expect($summary->zonePct())->toBe(['Z1' => 10, 'Z2' => 60, 'Z3' => 30]);
});

it('returns an empty zone breakdown when time_in_zone_pct is missing or malformed', function (array $data): void {
    expect(StreamSummary::fromArray($data)->zonePct())->toBe([]);
})->with([
    'missing key' => [[]],
    'null value' => [['time_in_zone_pct' => null]],
    'not an array' => [['time_in_zone_pct' => 'nope']],
]);

it('sums Z3+Z4+Z5 as the hard-zone share', function (): void {
    $summary = StreamSummary::fromArray([
        'time_in_zone_pct' => ['Z1' => 10, 'Z2' => 20, 'Z3' => 30, 'Z4' => 25, 'Z5' => 15],
    ]);

    expect($summary->hardZoneShare())->toBe(70.0);
});

it('treats missing zone keys as zero when summing the hard-zone share', function (): void {
    $summary = StreamSummary::fromArray(['time_in_zone_pct' => ['Z1' => 40, 'Z2' => 60]]);

    expect($summary->hardZoneShare())->toBe(0.0);
});

it('returns a zero hard-zone share when time_in_zone_pct is missing or malformed', function (array $data): void {
    expect(StreamSummary::fromArray($data)->hardZoneShare())->toBe(0.0);
})->with([
    'missing key' => [[]],
    'null value' => [['time_in_zone_pct' => null]],
    'not an array' => [['time_in_zone_pct' => 'nope']],
]);

it('treats a null blob as an empty summary', function (): void {
    $summary = StreamSummary::fromArray(null);

    expect($summary->isEmpty())->toBeTrue()
        ->and($summary->toArray())->toBe([])
        ->and($summary->zonePct())->toBe([])
        ->and($summary->zoneMinutes())->toBeNull();
});

it('round-trips the blob it was built from', function (): void {
    $data = ['decoupling_pct' => 3.4, 'negative_split' => true];

    expect(StreamSummary::fromArray($data)->toArray())->toBe($data)
        ->and(StreamSummary::fromArray($data)->isEmpty())->toBeFalse();
});

it('reads zone minutes as an array and null when absent', function (): void {
    expect(StreamSummary::fromArray(['time_in_zone_min' => ['Z2' => 32.5]])->zoneMinutes())
        ->toBe(['Z2' => 32.5])
        ->and(StreamSummary::fromArray([])->zoneMinutes())->toBeNull();
});

it('builds the best-effort pace key from the window label', function (): void {
    $summary = StreamSummary::fromArray([
        'best_30s_pace' => '3:20',
        'best_5min_pace' => '4:05',
        'best_60min_pace' => '5:12',
    ]);

    expect($summary->bestPace('30s'))->toBe('3:20')
        ->and($summary->bestPace('5min'))->toBe('4:05')
        ->and($summary->bestPace('60min'))->toBe('5:12')
        ->and($summary->bestPace('20min'))->toBeNull();
});

it('reports a best-effort pace as null when absent, null, or not a string', function (): void {
    expect(StreamSummary::fromArray([])->bestPace('5min'))->toBeNull()
        ->and(StreamSummary::fromArray(['best_5min_pace' => null])->bestPace('5min'))->toBeNull()
        ->and(StreamSummary::fromArray(['best_5min_pace' => 245])->bestPace('5min'))->toBeNull();
});

it('reads the per-km and partial-split rows, and null when the run carries neither', function (): void {
    $rows = [['km' => 1, 'pace' => '5:30']];
    $partial = ['distance_m' => 420, 'pace' => '5:10'];
    $summary = StreamSummary::fromArray(['per_km' => $rows, 'partial_split' => $partial]);

    expect($summary->perKm())->toBe($rows)
        ->and($summary->partialSplit())->toBe($partial)
        ->and(StreamSummary::fromArray([])->perKm())->toBeNull()
        ->and(StreamSummary::fromArray([])->partialSplit())->toBeNull();
});

it('reads the lap rows, and null when the run was never lapped', function (): void {
    $rows = [['lap' => 1, 'distance_m' => 647, 'elapsed_sec' => 250, 'pace' => '6:26']];

    expect(StreamSummary::fromArray(['laps' => $rows])->laps())->toBe($rows)
        ->and(StreamSummary::fromArray([])->laps())->toBeNull()
        ->and(StreamSummary::fromArray(['laps' => null])->laps())->toBeNull();
});

it('reads negative_split only when it is a real boolean', function (mixed $value, ?bool $expected): void {
    expect(StreamSummary::fromArray(['negative_split' => $value])->negativeSplit())->toBe($expected);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    'null value' => [null, null],
    'stringly true' => ['true', null],
    'numeric' => [1, null],
]);

it('reports negative_split as null when the key is absent', function (): void {
    expect(StreamSummary::fromArray([])->negativeSplit())->toBeNull();
});

it('reads every float-valued metric', function (string $method, string $key): void {
    expect(StreamSummary::fromArray([$key => 12.5])->{$method}())->toBe(12.5);
})->with([
    ['paceVariabilitySec', 'pace_variability_sec'],
    ['hrDriftBpm', 'hr_drift_bpm'],
    ['cadenceDropSpm', 'cadence_drop_spm'],
    ['decouplingPct', 'decoupling_pct'],
    ['optimalCadencePct', 'optimal_cadence_pct'],
    ['maxGradePct', 'max_grade_pct'],
    ['climbTimePct', 'climb_time_pct'],
]);

it('reports a float-valued metric as null when absent, null, or not numeric', function (string $method, string $key): void {
    expect(StreamSummary::fromArray([])->{$method}())->toBeNull()
        ->and(StreamSummary::fromArray([$key => null])->{$method}())->toBeNull()
        ->and(StreamSummary::fromArray([$key => 'nope'])->{$method}())->toBeNull();
})->with([
    ['paceVariabilitySec', 'pace_variability_sec'],
    ['hrDriftBpm', 'hr_drift_bpm'],
    ['cadenceDropSpm', 'cadence_drop_spm'],
    ['decouplingPct', 'decoupling_pct'],
    ['optimalCadencePct', 'optimal_cadence_pct'],
    ['maxGradePct', 'max_grade_pct'],
    ['climbTimePct', 'climb_time_pct'],
]);

it('widens an integer-valued float metric without changing its magnitude', function (): void {
    expect(StreamSummary::fromArray(['decoupling_pct' => 3])->decouplingPct())->toBe(3.0);
});

it('separates a decoupling reading of zero from no reading at all', function (): void {
    expect(StreamSummary::fromArray(['decoupling_pct' => 0.0])->hasDecouplingPct())->toBeTrue()
        ->and(StreamSummary::fromArray(['decoupling_pct' => 0.0])->decouplingPct())->toBe(0.0)
        ->and(StreamSummary::fromArray([])->hasDecouplingPct())->toBeFalse()
        ->and(StreamSummary::fromArray(['decoupling_pct' => null])->hasDecouplingPct())->toBeFalse();
});

it('reads the cadence distribution and falls back to an empty band table', function (mixed $value): void {
    expect(StreamSummary::fromArray(['cadence_distribution_pct' => ['<165' => 12.0]])->cadenceDistributionPct())
        ->toBe(['<165' => 12.0])
        ->and(StreamSummary::fromArray(['cadence_distribution_pct' => $value])->cadenceDistributionPct())->toBe([])
        ->and(StreamSummary::fromArray([])->cadenceDistributionPct())->toBe([]);
})->with([
    'null value' => [null],
    'not an array' => ['nope'],
]);

it('reads gap_pace only when it is a string', function (): void {
    expect(StreamSummary::fromArray(['gap_pace' => '5:04'])->gapPace())->toBe('5:04')
        ->and(StreamSummary::fromArray([])->gapPace())->toBeNull()
        ->and(StreamSummary::fromArray(['gap_pace' => null])->gapPace())->toBeNull()
        ->and(StreamSummary::fromArray(['gap_pace' => 304])->gapPace())->toBeNull();
});

it('reads every integer-valued metric', function (string $method, string $key): void {
    expect(StreamSummary::fromArray([$key => 42])->{$method}())->toBe(42)
        ->and(StreamSummary::fromArray([])->{$method}())->toBeNull()
        ->and(StreamSummary::fromArray([$key => null])->{$method}())->toBeNull()
        ->and(StreamSummary::fromArray([$key => 'nope'])->{$method}())->toBeNull();
})->with([
    ['descentM', 'descent_m'],
    ['stoppedTimeSec', 'stopped_time_sec'],
    ['stopCount', 'stop_count'],
]);
