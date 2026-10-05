<?php

declare(strict_types=1);

use App\Models\ActivityDetail;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\RunDistanceTimes;

/**
 * @return list<array{km: int, pace: string, elapsed_sec: int, distance_m: int}>
 */
function steadySplits(int $count, int $elapsedSec): array
{
    $rows = [];
    for ($km = 1; $km <= $count; $km++) {
        $rows[] = ['km' => $km, 'pace' => PaceFormatter::format((float) $elapsedSec), 'elapsed_sec' => $elapsedSec, 'distance_m' => 1000];
    }

    return $rows;
}

it('interpolates time at distance from splits (no walk-past inflation)', function (): void {
    $splits = steadySplits(21, 480);
    for ($km = 22; $km <= 25; $km++) {
        $splits[] = ['km' => $km, 'pace' => '15:00', 'elapsed_sec' => 900, 'distance_m' => 1000];
    }

    expect(RunDistanceTimes::timeAtDistance($splits, 21097.5))->toBeFloat()->toEqualWithDelta(10167.75, 1.0);
});

it('records the fastest embedded window, not the opening segment, on a negative-split run', function (): void {
    $splits = [...steadySplits(5, 400), ...steadySplits(5, 300)];

    expect(RunDistanceTimes::timeAtDistance($splits, 5000.0))->toBeFloat()->toEqualWithDelta(1500.0, 0.01);
});

it('reads elapsed_sec, so paused seconds count toward the PR like the watch counts them', function (): void {
    $splits = [];
    for ($km = 1; $km <= 5; $km++) {
        $splits[] = ['km' => $km, 'pace' => '15:00', 'elapsed_sec' => 900, 'moving_time' => 600, 'distance_m' => 1000];
    }

    expect(RunDistanceTimes::timeAtDistance($splits, 5000.0))->toBeFloat()->toEqualWithDelta(4500.0, 0.01);
});

it('returns null when splits do not reach the target distance', function (): void {
    expect(RunDistanceTimes::timeAtDistance(steadySplits(2, 400), 10_000))->toBeNull();
});

it('times every standard distance the run covers, including the trailing partial kilometre', function (): void {
    $detail = new ActivityDetail([
        'distance' => 10_400,
        'stream_summary' => [
            'per_km' => steadySplits(10, 360),
            'partial_split' => ['pace' => '6:00', 'distance_m' => 400],
        ],
    ]);

    expect(RunDistanceTimes::forDetail($detail))->toEqualWithDelta(['1km' => 360.0, '5km' => 1800.0, '10km' => 3600.0], 0.01);
});

it('skips a distance the run falls short of', function (): void {
    $detail = new ActivityDetail(['distance' => 4_900, 'stream_summary' => ['per_km' => steadySplits(4, 360)]]);

    expect(RunDistanceTimes::forDetail($detail))->toBe(['1km' => 360.0]);
});

it('reads a run\'s fastest split at a distance with the heart rate its splits averaged, or none when a split lacks one', function (): void {
    $rows = [
        ['km' => 1, 'pace' => '6:40', 'elapsed_sec' => 400, 'distance_m' => 1000, 'avg_hr' => 130],
        ['km' => 2, 'pace' => '6:40', 'elapsed_sec' => 400, 'distance_m' => 1000, 'avg_hr' => 135],
        ...array_map(static fn (int $km): array => ['km' => $km, 'pace' => '4:50', 'elapsed_sec' => 290, 'distance_m' => 1000, 'avg_hr' => 170 + $km], range(3, 7)),
    ];
    $withHeartRate = new ActivityDetail(['distance' => 7_000.0, 'stream_summary' => ['per_km' => $rows]]);
    $rows[4] = array_diff_key($rows[4], ['avg_hr' => 0]);
    $missingOne = new ActivityDetail(['distance' => 7_000.0, 'stream_summary' => ['per_km' => $rows]]);

    expect(RunDistanceTimes::bestSplit($withHeartRate, 5_000.0))->toEqual(['time_sec' => 1_450.0, 'heart_rate' => 175.0])
        ->and(RunDistanceTimes::bestSplit($missingOne, 5_000.0))->toEqual(['time_sec' => 1_450.0, 'heart_rate' => null])
        ->and(RunDistanceTimes::bestSplit($withHeartRate, 10_000.0))->toBeNull();
});
