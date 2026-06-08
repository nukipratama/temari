<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('casts numeric, boolean, datetime, and json columns', function (): void {
    $detail = ActivityDetail::factory()->create([
        'start_date_local' => '2026-04-26 16:20:08',
        'distance' => '10001.23',
        'moving_time' => '3600',
        'has_heartrate' => 1,
        'weather_rain_detected' => 0,
        'weather_temp_c' => '30',
        'splits_metric' => ['a', 'b'],
        'stream_summary' => ['avg_pace' => '6:00'],
    ]);

    expect($detail->start_date_local)->toBeInstanceOf(Carbon::class)
        ->and($detail->start_date_local->toDateTimeString())->toBe('2026-04-26 16:20:08')
        ->and($detail->distance)->toBeFloat()->toEqualWithDelta(10001.23, 0.01)
        ->and($detail->moving_time)->toBe(3600)
        ->and($detail->has_heartrate)->toBeTrue()
        ->and($detail->weather_rain_detected)->toBeFalse()
        ->and($detail->weather_temp_c)->toBe(30)
        ->and($detail->splits_metric)->toBe(['a', 'b'])
        ->and($detail->stream_summary)->toBe(['avg_pace' => '6:00']);
});

it('belongs to one activity', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create();

    expect($detail->activity)->toBeInstanceOf(Activity::class)
        ->and($detail->activity->is($activity))->toBeTrue();
});

it('enforces one detail per activity', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create();

    expect(fn () => ActivityDetail::factory()->for($activity)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('cascades deletion from activity', function (): void {
    $detail = ActivityDetail::factory()->create();
    $activityId = $detail->activity_id;

    Activity::query()->whereKey($activityId)->delete();

    expect(ActivityDetail::query()->find($detail->id))->toBeNull();
});
