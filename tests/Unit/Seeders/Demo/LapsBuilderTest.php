<?php

declare(strict_types=1);

use Database\Seeders\Demo\LapsBuilder;

/**
 * @return array<string, array{data: list<int|float>}>
 */
function evenPacedStreams(int $seconds, float $metresPerSecond, bool $withHeartrate = false): array
{
    $samples = range(0, $seconds);

    return [
        'time' => ['data' => $samples],
        'distance' => ['data' => array_map(fn (int $i): float => $i * $metresPerSecond, $samples)],
        'heartrate' => ['data' => $withHeartrate ? array_fill(0, count($samples), 150) : []],
    ];
}

it('cuts a plain 1 km grid when no manual lap lengths are given', function (): void {
    $laps = new LapsBuilder()->build(evenPacedStreams(500, 10.0));

    expect($laps)->toHaveCount(5)
        ->and(array_column($laps, 'distance'))->toBe([1000.0, 1000.0, 1000.0, 1000.0, 1000.0])
        ->and(array_column($laps, 'lap_index'))->toBe([1, 2, 3, 4, 5]);
});

it('cuts at the given manual lap lengths, last lap absorbing the remainder', function (): void {
    $laps = new LapsBuilder()->build(evenPacedStreams(300, 10.0), [1_000, 800, 400]);

    expect(array_column($laps, 'distance'))->toBe([1000.0, 800.0, 400.0, 800.0])
        ->and(array_sum(array_column($laps, 'distance')))->toEqualWithDelta(3000.0, 0.1);
});

it('lap distances sum to the total stream distance even when it lands mid-lap', function (): void {
    $streams = evenPacedStreams(299, 10.05);

    $laps = new LapsBuilder()->build($streams);

    expect(array_sum(array_column($laps, 'distance')))
        ->toEqualWithDelta((float) end($streams['distance']['data']), 0.1);
});

it('reports elapsed_time equal to moving_time (auto-pause is off on the watch)', function (): void {
    $laps = new LapsBuilder()->build(evenPacedStreams(500, 10.0));

    foreach ($laps as $lap) {
        expect($lap['elapsed_time'])->toBe($lap['moving_time'])
            ->and($lap['elapsed_time'])->toBe(100);
    }
});

it('stops cutting once the requested lap lengths outrun the recorded distance', function (): void {
    $laps = new LapsBuilder()->build(evenPacedStreams(150, 10.0), [1_000, 5_000, 5_000]);

    expect(array_column($laps, 'distance'))->toBe([1000.0, 500.0]);
});

it('omits average_heartrate when no HR was recorded', function (): void {
    $withHr = new LapsBuilder()->build(evenPacedStreams(500, 10.0, withHeartrate: true));
    $withoutHr = new LapsBuilder()->build(evenPacedStreams(500, 10.0));

    expect($withHr[0])->toHaveKey('average_heartrate')
        ->and($withHr[0]['average_heartrate'])->toEqualWithDelta(150.0, 0.1)
        ->and($withoutHr[0])->not->toHaveKey('average_heartrate');
});

it('returns no laps for streams too short to cut', function (): void {
    expect(new LapsBuilder()->build(['time' => ['data' => [0]], 'distance' => ['data' => [0]]]))->toBe([])
        ->and(new LapsBuilder()->build([]))->toBe([]);
});
