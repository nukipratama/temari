<?php

declare(strict_types=1);

use Database\Seeders\Demo\SplitsBuilder;

it('split distances sum to total stream distance', function (): void {
    $streams = [
        'time' => ['data' => range(0, 299)],
        'distance' => ['data' => array_map(fn (int $i): float => round($i * 33.45, 2), range(0, 299))],
        'heartrate' => ['data' => []],
    ];

    $splits = (new SplitsBuilder())->build($streams);

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

    $splits = (new SplitsBuilder())->build($streams);

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

    expect((new SplitsBuilder())->build($streams))->toBe([]);
});
