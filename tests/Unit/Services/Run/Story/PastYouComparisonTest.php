<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Enums\TrendDirection;
use App\Services\Run\Story\ComparableRun;
use App\Services\Run\Story\PastYouComparison;
use Illuminate\Support\Carbon;

function comparisonRun(string $date, float $paceSecPerKm, ?float $hr, int $activityId = 1): ComparableRun
{
    return new ComparableRun(
        activityId: $activityId,
        startedAt: Carbon::parse($date.' 06:00:00'),
        distanceM: 10_000.0,
        movingTimeSec: (int) round($paceSecPerKm * 10),
        paceSecPerKm: $paceSecPerKm,
        averageHeartrate: $hr,
        elevationGainM: 50.0,
        ingestState: IngestState::Summary,
    );
}

it('reports the deltas of a matched pair', function (): void {
    $comparison = PastYouComparison::between(
        comparisonRun('2026-06-15', 420.0, 152.0, 2),
        comparisonRun('2026-02-15', 435.0, 160.0, 1),
        0.87654,
    );

    expect($comparison->paceDeltaSec)->toBe(15.0)
        ->and($comparison->hrDeltaBpm)->toBe(-8.0)
        ->and($comparison->daysApart)->toBe(120)
        ->and($comparison->similarity)->toBe(0.877);
});

it('leaves the heart-rate delta null when either side has none', function (): void {
    $comparison = PastYouComparison::between(
        comparisonRun('2026-06-15', 420.0, null, 2),
        comparisonRun('2026-02-15', 435.0, 160.0, 1),
        0.9,
    );

    expect($comparison->hrDeltaBpm)->toBeNull();
});

it('calls a pair better or worse once the pace gap clears the noise band', function (float $currentPace, TrendDirection $expected): void {
    $comparison = PastYouComparison::between(
        comparisonRun('2026-06-15', $currentPace, 155.0, 2),
        comparisonRun('2026-02-15', 430.0, 155.0, 1),
        0.9,
    );

    expect($comparison->direction())->toBe($expected);
})->with([
    'clearly faster' => [420.0, TrendDirection::Better],
    'exactly at the threshold, faster' => [425.0, TrendDirection::Better],
    'inside the noise band' => [428.0, TrendDirection::Flat],
    'exactly at the threshold, slower' => [435.0, TrendDirection::Worse],
    'clearly slower' => [445.0, TrendDirection::Worse],
]);

it('lets heart rate decide when pace came back flat', function (?float $currentHr, TrendDirection $expected): void {
    $comparison = PastYouComparison::between(
        comparisonRun('2026-06-15', 430.0, $currentHr, 2),
        comparisonRun('2026-02-15', 430.0, 155.0, 1),
        0.9,
    );

    expect($comparison->direction())->toBe($expected);
})->with([
    'same pace, lower heart rate' => [150.0, TrendDirection::Better],
    'same pace, higher heart rate' => [160.0, TrendDirection::Worse],
    'same pace, heart rate inside noise' => [153.0, TrendDirection::Flat],
    'same pace, no heart rate' => [null, TrendDirection::Flat],
]);

it('does not let heart rate override a decided pace gap', function (): void {
    $comparison = PastYouComparison::between(
        comparisonRun('2026-06-15', 410.0, 170.0, 2),
        comparisonRun('2026-02-15', 430.0, 150.0, 1),
        0.9,
    );

    expect($comparison->direction())->toBe(TrendDirection::Better);
});

it('serializes both sides of the pair alongside the deltas', function (): void {
    $comparison = PastYouComparison::between(
        comparisonRun('2026-06-15', 420.0, 152.0, 2),
        comparisonRun('2026-02-15', 435.0, 160.0, 1),
        0.9,
    );

    expect($comparison->toArray())->toMatchArray([
        'direction' => 'better',
        'days_apart' => 120,
        'similarity' => 0.9,
        'pace_delta_sec' => 15.0,
        'hr_delta_bpm' => -8.0,
    ])
        ->and($comparison->toArray()['current']['activity_id'])->toBe(2)
        ->and($comparison->toArray()['past']['activity_id'])->toBe(1);
});
