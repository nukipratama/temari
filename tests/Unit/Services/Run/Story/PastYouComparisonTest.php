<?php

declare(strict_types=1);

use App\Enums\ComparisonMetric;
use App\Enums\IngestState;
use App\Enums\TrendDirection;
use App\Services\Run\Story\ComparableRun;
use App\Services\Run\Story\PastYouComparison;
use Illuminate\Support\Carbon;

function comparisonRun(string $date, float $paceSecPerKm, ?float $hr, int $activityId = 1, ?int $elapsedTimeSec = null): ComparableRun
{
    return new ComparableRun(
        activityId: $activityId,
        startedAt: Carbon::parse($date.' 06:00:00'),
        distanceM: 10_000.0,
        elapsedTimeSec: $elapsedTimeSec ?? (int) round($paceSecPerKm * 10),
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

it('decides a pair with heart rate on both sides by efficiency, from a 3% change', function (float $currentHr, TrendDirection $expected): void {
    $comparison = PastYouComparison::between(
        comparisonRun('2026-06-15', 430.0, $currentHr, 2),
        comparisonRun('2026-02-15', 430.0, 155.0, 1),
        0.9,
    );

    expect($comparison->metric())->toBe(ComparisonMetric::Ef)
        ->and($comparison->direction())->toBe($expected);
})->with([
    '3.0% more efficient' => [155.0 / 1.03, TrendDirection::Better],
    '2.9% more efficient' => [155.0 / 1.029, TrendDirection::Flat],
    '2.9% less efficient' => [155.0 / 0.971, TrendDirection::Flat],
    '3.0% less efficient' => [155.0 / 0.97, TrendDirection::Worse],
]);

it('calls a pair better on efficiency at identical pace', function (): void {
    $comparison = PastYouComparison::between(
        comparisonRun('2026-06-15', 430.0, 148.0, 2),
        comparisonRun('2026-02-15', 430.0, 155.0, 1),
        0.9,
    );

    expect($comparison->direction())->toBe(TrendDirection::Better);
});

it('calls a faster pair at a proportionally higher heart rate flat', function (): void {
    $comparison = PastYouComparison::between(
        comparisonRun('2026-06-15', 400.0, 155.0 * 430.0 / 400.0, 2),
        comparisonRun('2026-02-15', 430.0, 155.0, 1),
        0.9,
    );

    expect($comparison->paceChangePct())->toBeGreaterThan(2.0)
        ->and($comparison->direction())->toBe(TrendDirection::Flat)
        ->and($comparison->toArray()['pace_relation'])->toBe('faster');
});

it('calls a faster pair flat when its efficiency gain stays under 3%', function (): void {
    $comparison = PastYouComparison::between(
        comparisonRun('2026-06-15', 415.0, 157.0, 2),
        comparisonRun('2026-02-15', 430.0, 155.0, 1),
        0.9,
    );

    expect($comparison->paceChangePct())->toBeGreaterThan(3.0)
        ->and($comparison->changePct())->toBeLessThan(3.0)
        ->and($comparison->direction())->toBe(TrendDirection::Flat);
});

it('falls back to pace at a 2% change when either side has no heart rate', function (?float $currentHr, ?float $pastHr, float $currentPace, TrendDirection $expected): void {
    $comparison = PastYouComparison::between(
        comparisonRun('2026-06-15', $currentPace, $currentHr, 2),
        comparisonRun('2026-02-15', 430.0, $pastHr, 1),
        0.9,
    );

    expect($comparison->metric())->toBe(ComparisonMetric::Pace)
        ->and($comparison->direction())->toBe($expected);
})->with([
    'current missing, 2.0% faster' => [null, 155.0, 421.4, TrendDirection::Better],
    'current missing, 1.9% faster' => [null, 155.0, 421.83, TrendDirection::Flat],
    'past missing, 1.9% slower' => [150.0, null, 438.17, TrendDirection::Flat],
    'past missing, 2.0% slower' => [150.0, null, 438.6, TrendDirection::Worse],
    'both missing, same pace' => [null, null, 430.0, TrendDirection::Flat],
]);

it('falls back to pace when either run is under 20 minutes', function (int $currentElapsed, int $pastElapsed, ComparisonMetric $expected): void {
    $comparison = PastYouComparison::between(
        comparisonRun('2026-06-15', 430.0, 140.0, 2, $currentElapsed),
        comparisonRun('2026-02-15', 430.0, 155.0, 1, $pastElapsed),
        0.9,
    );

    expect($comparison->metric())->toBe($expected)
        ->and($comparison->direction())->toBe($expected === ComparisonMetric::Ef ? TrendDirection::Better : TrendDirection::Flat);
})->with([
    'both exactly 20 minutes' => [1_200, 1_200, ComparisonMetric::Ef],
    'current a second short' => [1_199, 1_200, ComparisonMetric::Pace],
    'past a second short' => [1_200, 1_199, ComparisonMetric::Pace],
]);

it('expresses a change in multiples of its own metric\'s threshold', function (): void {
    $efPair = PastYouComparison::between(
        comparisonRun('2026-06-15', 430.0, 155.0 / 1.06, 2),
        comparisonRun('2026-02-15', 430.0, 155.0, 1),
        0.9,
    );
    $pacePair = PastYouComparison::between(
        comparisonRun('2026-06-15', 438.6, null, 2),
        comparisonRun('2026-02-15', 430.0, 155.0, 1),
        0.9,
    );

    expect($efPair->signalUnits())->toEqualWithDelta(2.0, 0.001)
        ->and($pacePair->signalUnits())->toEqualWithDelta(-1.0, 0.001);
});

it('bands a pace change into faster, slower or the same at 2%', function (): void {
    expect(PastYouComparison::paceRelation(2.0))->toBe('faster')
        ->and(PastYouComparison::paceRelation(1.99))->toBe('same')
        ->and(PastYouComparison::paceRelation(-1.99))->toBe('same')
        ->and(PastYouComparison::paceRelation(-2.0))->toBe('slower');
});

it('exposes the direction rule as a static call for callers with no instance', function (): void {
    expect(PastYouComparison::directionFor(ComparisonMetric::Ef, 3.0))->toBe(TrendDirection::Better)
        ->and(PastYouComparison::directionFor(ComparisonMetric::Ef, 2.99))->toBe(TrendDirection::Flat)
        ->and(PastYouComparison::directionFor(ComparisonMetric::Pace, 2.0))->toBe(TrendDirection::Better)
        ->and(PastYouComparison::directionFor(ComparisonMetric::Pace, -2.0))->toBe(TrendDirection::Worse);
});

it('serializes both sides of the pair alongside the deltas', function (): void {
    $comparison = PastYouComparison::between(
        comparisonRun('2026-06-15', 420.0, 152.0, 2),
        comparisonRun('2026-02-15', 435.0, 160.0, 1),
        0.9,
    );

    expect($comparison->toArray())->toMatchArray([
        'direction' => 'better',
        'metric' => 'ef',
        'pace_relation' => 'faster',
        'days_apart' => 120,
        'similarity' => 0.9,
        'pace_delta_sec' => 15.0,
        'hr_delta_bpm' => -8.0,
    ])
        ->and($comparison->toArray()['current']['activity_id'])->toBe(2)
        ->and($comparison->toArray()['past']['activity_id'])->toBe(1);
});
