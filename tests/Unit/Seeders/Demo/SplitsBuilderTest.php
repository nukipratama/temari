<?php

declare(strict_types=1);

use Database\Seeders\Demo\SplitsBuilder;

it('split distances sum to total stream distance', function (): void {
    $streams = [
        'time' => ['data' => range(0, 299)],
        'distance' => ['data' => array_map(fn (int $i): float => round($i * 33.45, 2), range(0, 299))],
        'heartrate' => ['data' => []],
    ];

    $splits = new SplitsBuilder()->build($streams);

    $totalStreamDist = (float) end($streams['distance']['data']);
    $splitSum = array_sum(array_map(fn (array $s): float => (float) $s['distance'], $splits));

    expect($splitSum)->toEqualWithDelta($totalStreamDist, 0.1);
});

it('last split absorbs rounding deficit so total is within 0.1', function (): void {
    $streams = [
        'time' => ['data' => [0, 100, 200, 300, 400, 500]],
        'distance' => ['data' => [0, 999.95, 1999.93, 2999.88, 3999.91, 4998.70]],
        'heartrate' => ['data' => []],
    ];

    $splits = new SplitsBuilder()->build($streams);

    $totalStreamDist = (float) end($streams['distance']['data']);
    $splitSum = array_sum(array_map(fn (array $s): float => (float) $s['distance'], $splits));

    expect($splitSum)->toEqualWithDelta($totalStreamDist, 0.1);
});

it('returns empty splits for short streams', function (): void {
    $streams = [
        'time' => ['data' => [0]],
        'distance' => ['data' => [0]],
        'heartrate' => ['data' => []],
    ];

    expect(new SplitsBuilder()->build($streams))->toBe([]);
});

it('returns empty splits when time and distance arrays have mismatched lengths', function (): void {
    $streams = [
        'time' => ['data' => [0, 1, 2, 3]],
        'distance' => ['data' => [0, 10, 20]],
        'heartrate' => ['data' => []],
    ];

    expect(new SplitsBuilder()->build($streams))->toBe([]);
});

it('includes average_heartrate when a heartrate stream is present, mirroring Strava', function (): void {
    $streams = [
        'time' => ['data' => range(0, 299)],
        'distance' => ['data' => array_map(fn (int $i): float => round($i * 33.45, 2), range(0, 299))],
        'heartrate' => ['data' => array_fill(0, 300, 150)],
    ];

    $splits = new SplitsBuilder()->build($streams);

    expect($splits)->not->toBeEmpty();
    foreach ($splits as $split) {
        expect($split)->toHaveKey('average_heartrate')
            ->and($split['average_heartrate'])->toBe(150.0);
    }
});

it('omits average_heartrate entirely when no heartrate stream was paired', function (): void {
    $streams = [
        'time' => ['data' => range(0, 299)],
        'distance' => ['data' => array_map(fn (int $i): float => round($i * 33.45, 2), range(0, 299))],
        'heartrate' => ['data' => []],
    ];

    $splits = new SplitsBuilder()->build($streams);

    expect($splits)->not->toBeEmpty();
    foreach ($splits as $split) {
        expect($split)->not->toHaveKey('average_heartrate');
    }
});

it('drops a trailing leftover distance under 100m instead of appending a tiny final split', function (): void {
    $streams = [
        'time' => ['data' => [0, 500, 1000, 1050]],
        'distance' => ['data' => [0, 500, 1000, 1050]],
        'heartrate' => ['data' => []],
    ];

    $splits = new SplitsBuilder()->build($streams);

    // Only the one full-km split; the 50m leftover (< 100m) is dropped, not appended.
    expect($splits)->toHaveCount(1);
});

it('appends a trailing leftover distance of 100m or more as a final split', function (): void {
    $streams = [
        'time' => ['data' => [0, 500, 1000, 1150]],
        'distance' => ['data' => [0, 500, 1000, 1150]],
        'heartrate' => ['data' => []],
    ];

    $splits = new SplitsBuilder()->build($streams);

    expect($splits)->toHaveCount(2)
        ->and($splits[1]['distance'])->toBe(150.0);
});
