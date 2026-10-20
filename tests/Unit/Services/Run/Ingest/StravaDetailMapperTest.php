<?php

declare(strict_types=1);

use App\Services\Run\Ingest\StravaDetailMapper;

it('maps the summary fields and keeps detail-only fields out', function (): void {
    $mapped = StravaDetailMapper::summary([
        'name' => 'Run', 'start_date' => '2026-05-09T23:00:00Z', 'start_date_local' => '2026-05-10T06:00:00Z',
        'distance' => 5000.0, 'has_heartrate' => 1, 'map' => ['summary_polyline' => 'p'],
        'start_latlng' => ['-6.2', '106.8'], 'calories' => 500.0,
    ]);

    expect($mapped)->toMatchArray([
        'name' => 'Run',
        'start_date_local' => '2026-05-10 06:00:00',
        'start_date_utc' => '2026-05-09 23:00:00',
        'distance' => 5000.0,
        'has_heartrate' => true,
        'summary_polyline' => 'p',
        'start_lat' => -6.2,
        'start_lng' => 106.8,
        'moving_time' => null,
    ])->and($mapped)->not->toHaveKey('calories');
});

it('adds the detail-only fields', function (): void {
    $mapped = StravaDetailMapper::detail([
        'calories' => 500.0, 'splits_metric' => [['split' => 1]], 'laps' => [['lap_index' => 1]],
        'suffer_score' => 90, 'device_name' => 'Watch', 'average_watts' => 250.5,
    ]);

    expect($mapped)->toMatchArray([
        'calories' => 500.0, 'splits_metric' => [['split' => 1]], 'laps' => [['lap_index' => 1]],
        'suffer_score' => 90, 'device_name' => 'Watch', 'average_watts' => 250.5, 'name' => null,
    ]);
});

it('never turns an empty start date into now', function (): void {
    expect(StravaDetailMapper::detail(['start_date_local' => '', 'start_date' => ''])['start_date_local'])->toBeNull()
        ->and(StravaDetailMapper::summary(['start_date_local' => ''])['start_date_local'])->toBeNull();
});

it('falls back to start_date for the local start and leaves non-GPS coordinates null', function (): void {
    $mapped = StravaDetailMapper::summary(['start_date' => '2026-05-10T06:00:00Z', 'start_latlng' => []]);

    expect($mapped['start_date_local'])->toBe('2026-05-10 06:00:00')
        ->and($mapped['start_lat'])->toBeNull()
        ->and($mapped['start_lng'])->toBeNull();
});
