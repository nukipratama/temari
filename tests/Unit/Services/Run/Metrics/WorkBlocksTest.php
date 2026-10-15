<?php

declare(strict_types=1);

use App\Services\Run\Metrics\WorkBlocks;

/** @return array<string, mixed> */
function lap(int $metres, int $seconds): array
{
    return ['distance_m' => $metres, 'elapsed_sec' => $seconds];
}

/**
 * A 1 Hz stream at the given pace (sec/km) for the given seconds, segment by segment.
 *
 * @param  list<array{0: int, 1: int}>  $segments  [seconds, pace sec/km]
 * @return array<string, mixed>
 */
function paceStream(array $segments): array
{
    $time = [];
    $velocity = [];
    $clock = 0;
    foreach ($segments as [$seconds, $pace]) {
        for ($i = 0; $i < $seconds; $i++) {
            $time[] = $clock++;
            $velocity[] = 1000 / $pace;
        }
    }

    return ['time' => ['data' => $time], 'velocity_smooth' => ['data' => $velocity]];
}

$twoTens = [['seconds' => 600.0, 'km' => 1.7], ['seconds' => 600.0, 'km' => 1.7]];

it('merges autosplit laps inside each block, split by the recovery', function () use ($twoTens): void {
    $laps = [
        lap(1000, 409), lap(398, 182),
        lap(1000, 325), lap(702, 235),
        lap(163, 120),
        lap(1000, 345), lap(701, 250),
        lap(1000, 416), lap(1000, 402), lap(337, 140),
    ];

    $blocks = WorkBlocks::fromLaps($laps, $twoTens, 363, 432);

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0]['seconds'])->toBe(560.0)
        ->and($blocks[0]['km'])->toEqualWithDelta(1.702, 0.001)
        ->and($blocks[1]['seconds'])->toBe(595.0)
        ->and($blocks[1]['km'])->toEqualWithDelta(1.701, 0.001);
});

it('reads no blocks when the merged laps do not match the prescription one for one', function () use ($twoTens): void {
    $oneLongBlock = [lap(1400, 590), lap(1000, 330), lap(1000, 330), lap(1000, 330), lap(2300, 960)];

    expect(WorkBlocks::fromLaps($oneLongBlock, $twoTens, 363, 432))->toBeNull();
});

it('reads no blocks when a lap group covers too little of its block', function () use ($twoTens): void {
    $shortBlocks = [lap(1400, 590), lap(800, 260), lap(300, 120), lap(1700, 600), lap(2300, 960)];

    expect(WorkBlocks::fromLaps($shortBlocks, $twoTens, 363, 432))->toBeNull();
});

it('accepts a block run by distance even when it took less than nine tenths of the minutes', function (): void {
    $laps = [lap(1400, 590), lap(1700, 500), lap(2300, 960)];

    expect(WorkBlocks::fromLaps($laps, [['seconds' => 600.0, 'km' => 1.7]], 363, 432))
        ->toBe([['seconds' => 500.0, 'km' => 1.7]]);
});

it('never reads blocks off the plain kilometre grid', function (): void {
    $grid = [lap(1000, 420), lap(1000, 330), lap(1000, 330), lap(1000, 420), lap(400, 170)];

    expect(WorkBlocks::fromLaps($grid, [['seconds' => 600.0, 'km' => 1.8]], 363, 432))->toBeNull();
});

it('finds each block as its own stretch in the stream, in the order they were run', function (): void {
    $stream = paceStream([[600, 430], [600, 330], [120, 700], [600, 350], [900, 430]]);

    $blocks = WorkBlocks::fromStream($stream, [600, 600]);

    expect($blocks)->toHaveCount(2)
        ->and((int) round($blocks[0]['seconds'] / $blocks[0]['km']))->toBe(330)
        ->and((int) round($blocks[1]['seconds'] / $blocks[1]['km']))->toBe(350);
});

it('measures a block at its exact length, not a shorter window', function (): void {
    $stream = paceStream([[600, 430], [300, 320], [600, 360], [600, 430]]);

    $blocks = WorkBlocks::fromStream($stream, [900]);

    expect((int) round($blocks[0]['seconds'] / $blocks[0]['km']))->toBe(346);
});

it('finds nothing when the recording cannot hold every block', function (): void {
    expect(WorkBlocks::fromStream(paceStream([[900, 330]]), [600, 600]))->toBeNull()
        ->and(WorkBlocks::fromStream([], [600]))->toBeNull();
});
