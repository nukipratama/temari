<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Services\Run\Story\ComparableRun;

/**
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function comparableRow(array $attributes = [], IngestState $ingestState = IngestState::Summary): array
{
    return array_merge([
        'activity_id' => 7,
        'start_date_local' => '2026-06-15 06:30:00',
        'distance' => 10_000.0,
        'moving_time' => 3_000,
        'average_heartrate' => 155.0,
        'total_elevation_gain' => 120.0,
        'ingest_state' => $ingestState->value,
    ], $attributes);
}

it('projects a query row onto the summary-only fields', function (): void {
    $run = ComparableRun::fromRow(comparableRow());

    expect($run)->not->toBeNull()
        ->and($run->activityId)->toBe(7)
        ->and($run->distanceM)->toBe(10_000.0)
        ->and($run->movingTimeSec)->toBe(3_000)
        ->and($run->paceSecPerKm)->toBe(300.0)
        ->and($run->averageHeartrate)->toBe(155.0)
        ->and($run->elevationGainM)->toBe(120.0)
        ->and($run->ingestState)->toBe(IngestState::Summary);
});

it('returns null when distance, moving time or start date is unusable', function (array $attributes): void {
    expect(ComparableRun::fromRow(comparableRow($attributes, IngestState::Detailed)))->toBeNull();
})->with([
    'no distance' => [['distance' => 0.0]],
    'no moving time' => [['moving_time' => 0]],
    'no start date' => [['start_date_local' => null]],
]);

it('derives distance, elevation density, clock time and month', function (): void {
    $run = ComparableRun::fromRow(comparableRow(ingestState: IngestState::Detailed));

    expect($run->distanceKm())->toBe(10.0)
        ->and($run->elevationPerKm())->toBe(12.0)
        ->and($run->minuteOfDay())->toBe(390)
        ->and($run->month())->toBe(6);
});

it('has no elevation density when the run carries no elevation', function (): void {
    $run = ComparableRun::fromRow(comparableRow(['total_elevation_gain' => null]));

    expect($run->elevationGainM)->toBeNull()
        ->and($run->elevationPerKm())->toBeNull();
});

it('measures whole days between two runs', function (): void {
    $past = ComparableRun::fromRow(comparableRow(['start_date_local' => '2026-01-01 20:00:00']));
    $current = ComparableRun::fromRow(comparableRow(['start_date_local' => '2026-03-02 05:00:00']));

    expect($past->daysBefore($current))->toBe(60);
});

it('serializes the ingest state so a summary-sourced comparison is visible', function (): void {
    $run = ComparableRun::fromRow(comparableRow());

    expect($run->toArray())->toBe([
        'activity_id' => 7,
        'date' => '2026-06-15',
        'km' => 10.0,
        'pace_sec_per_km' => 300.0,
        'average_heartrate' => 155.0,
        'elevation_gain_m' => 120.0,
        'ingest_state' => 'summary',
    ]);
});
