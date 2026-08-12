<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Ingest\SummaryIngest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function summaryPayload(int $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'name' => 'Lari pagi',
        'sport_type' => 'Run',
        'start_date_local' => '2026-05-10T06:00:00Z',
        'distance' => 10_120.5,
        'moving_time' => 3300,
        'elapsed_time' => 3400,
        'average_speed' => 3.07,
        'max_speed' => 4.2,
        'total_elevation_gain' => 62.0,
        'elev_high' => 88.1,
        'elev_low' => 26.4,
        'has_heartrate' => true,
        'average_heartrate' => 156.2,
        'max_heartrate' => 178,
        'workout_type' => 0,
        'map' => ['summary_polyline' => 'abc123'],
        'start_latlng' => [-6.2, 106.8],
        ...$overrides,
    ];
}

it('stores summaries as visible, summary-only activities', function (): void {
    $user = User::factory()->create();

    $inserted = app(SummaryIngest::class)->store($user->id, [summaryPayload(111), summaryPayload(222)]);

    expect($inserted)->toBe(2);

    $activity = Activity::query()->where('strava_external_id', 111)->firstOrFail();
    expect($activity->ingest_state)->toBe(IngestState::Summary)
        ->and($activity->analyzed_at)->not->toBeNull();
});

it('maps every field the summary endpoint carries onto the detail row', function (): void {
    $user = User::factory()->create();

    app(SummaryIngest::class)->store($user->id, [summaryPayload(111)]);

    $detail = ActivityDetail::query()->firstOrFail();

    expect($detail->name)->toBe('Lari pagi')
        ->and($detail->distance)->toBe(10_120.5)
        ->and($detail->moving_time)->toBe(3300)
        ->and($detail->elapsed_time)->toBe(3400)
        ->and($detail->average_speed)->toBe(3.07)
        ->and($detail->max_speed)->toBe(4.2)
        ->and($detail->total_elevation_gain)->toBe(62.0)
        ->and($detail->elev_high)->toBe(88.1)
        ->and($detail->elev_low)->toBe(26.4)
        ->and($detail->has_heartrate)->toBeTrue()
        ->and($detail->average_heartrate)->toBe(156.2)
        ->and($detail->max_heartrate)->toBe(178)
        ->and($detail->workout_type)->toBe(0)
        ->and($detail->summary_polyline)->toBe('abc123')
        ->and($detail->start_lat)->toBe(-6.2)
        ->and($detail->start_lng)->toBe(106.8)
        ->and($detail->start_date_local?->toDateString())->toBe('2026-05-10');
});

it('leaves every stream-derived column null so nothing is fabricated', function (): void {
    $user = User::factory()->create();

    app(SummaryIngest::class)->store($user->id, [summaryPayload(111)]);

    $detail = ActivityDetail::query()->firstOrFail();

    expect($detail->stream_summary)->toBeNull()
        ->and($detail->trimp_edwards)->toBeNull()
        ->and($detail->splits_metric)->toBeNull()
        ->and($detail->laps)->toBeNull()
        ->and($detail->calories)->toBeNull()
        ->and($detail->suffer_score)->toBeNull()
        ->and($detail->weather_temp_c)->toBeNull();
});

it('never downgrades an already-detailed activity', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create(['strava_external_id' => 111]);
    ActivityDetail::factory()->for($activity)->create(['name' => 'Sudah lengkap', 'trimp_edwards' => 120.0]);

    $inserted = app(SummaryIngest::class)->store($user->id, [summaryPayload(111)]);

    expect($inserted)->toBe(0)
        ->and($activity->refresh()->ingest_state)->toBe(IngestState::Detailed)
        ->and($activity->detail?->name)->toBe('Sudah lengkap')
        ->and($activity->detail?->trimp_edwards)->toBe(120.0);
});

it('fills in a stub left behind by a failed ingest and makes it visible', function (): void {
    $user = User::factory()->create();
    $stub = Activity::factory()->for($user)->stub()->create(['strava_external_id' => 111]);

    app(SummaryIngest::class)->store($user->id, [summaryPayload(111)]);

    $stub->refresh();
    expect($stub->analyzed_at)->not->toBeNull()
        ->and($stub->ingest_state)->toBe(IngestState::Summary)
        ->and(ActivityDetail::query()->where('activity_id', $stub->id)->value('name'))->toBe('Lari pagi');
});

it('is idempotent across repeated syncs of the same history', function (): void {
    $user = User::factory()->create();
    $summaries = [summaryPayload(111), summaryPayload(222)];

    app(SummaryIngest::class)->store($user->id, $summaries);
    $second = app(SummaryIngest::class)->store($user->id, $summaries);

    expect($second)->toBe(0)
        ->and(Activity::query()->withStubs()->count())->toBe(2)
        ->and(ActivityDetail::query()->count())->toBe(2);
});

it('ignores payloads with no usable id', function (): void {
    $user = User::factory()->create();

    expect(app(SummaryIngest::class)->store($user->id, []))->toBe(0)
        ->and(app(SummaryIngest::class)->store($user->id, [['sport_type' => 'Run'], ['id' => 0]]))->toBe(0)
        ->and(Activity::query()->withStubs()->count())->toBe(0);
});

it('stores a treadmill run without coordinates or a polyline', function (): void {
    $user = User::factory()->create();

    app(SummaryIngest::class)->store($user->id, [
        summaryPayload(111, ['start_latlng' => [], 'map' => [], 'has_heartrate' => false]),
    ]);

    $detail = ActivityDetail::query()->firstOrFail();

    expect($detail->start_lat)->toBeNull()
        ->and($detail->start_lng)->toBeNull()
        ->and($detail->summary_polyline)->toBeNull()
        ->and($detail->has_heartrate)->toBeFalse();
});
