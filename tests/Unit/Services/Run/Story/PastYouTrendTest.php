<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Enums\TrendVerdict;
use App\Services\Run\Story\ComparableRun;
use App\Services\Run\Story\PastYouComparison;
use App\Services\Run\Story\PastYouTrend;
use Illuminate\Support\Carbon;

function trendComparison(): PastYouComparison
{
    $run = fn (string $date, float $pace, int $id): ComparableRun => new ComparableRun(
        activityId: $id,
        startedAt: Carbon::parse($date.' 06:00:00'),
        distanceM: 10_000.0,
        movingTimeSec: (int) round($pace * 10),
        paceSecPerKm: $pace,
        averageHeartrate: 155.0,
        elevationGainM: 50.0,
        ingestState: IngestState::Detailed,
    );

    return PastYouComparison::between($run('2026-06-15', 420.0, 2), $run('2026-02-15', 435.0, 1), 0.9);
}

it('carries the verdict, its evidence and the supporting readings', function (): void {
    $trend = new PastYouTrend(
        verdict: TrendVerdict::Improving,
        comparisons: [trendComparison()],
        windowDays: 42,
        meanPaceDeltaSec: 15.0,
        meanHrDeltaBpm: -4.0,
        fitnessDeltaCtl: 3.2,
        paceConsistencyNow: 'sangat rata',
        paceConsistencyThen: 'agak naik-turun',
        relativeEffortBand: 'typical',
    );

    expect($trend->toArray())->toMatchArray([
        'verdict' => 'improving',
        'window_days' => 42,
        'comparison_count' => 1,
        'mean_pace_delta_sec' => 15.0,
        'mean_hr_delta_bpm' => -4.0,
        'fitness_delta_ctl' => 3.2,
        'pace_consistency_now' => 'sangat rata',
        'pace_consistency_then' => 'agak naik-turun',
        'relative_effort_band' => 'typical',
    ])->and($trend->toArray()['comparisons'][0]['direction'])->toBe('better');
});

it('builds the empty state as an outcome, not an error', function (): void {
    $trend = PastYouTrend::notEnoughHistory(42);

    expect($trend->verdict)->toBe(TrendVerdict::NotEnoughHistory)
        ->and($trend->verdict->isJudged())->toBeFalse()
        ->and($trend->toArray())->toMatchArray([
            'verdict' => 'not_enough_history',
            'comparison_count' => 0,
            'comparisons' => [],
            'mean_pace_delta_sec' => null,
            'fitness_delta_ctl' => null,
        ]);
});

it('keeps the one pair it did find so the empty state can say how close it is', function (): void {
    $trend = PastYouTrend::notEnoughHistory(42, [trendComparison()]);

    expect($trend->toArray()['comparison_count'])->toBe(1)
        ->and($trend->verdict)->toBe(TrendVerdict::NotEnoughHistory);
});
