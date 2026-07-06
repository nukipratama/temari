<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('forUser scopes to details whose activity belongs to the user', function (): void {
    $user = User::factory()->create();
    $mine = ActivityDetail::factory()->for(Activity::factory()->for($user))->create();
    ActivityDetail::factory()->create(); // another user

    expect(ActivityDetail::query()->forUser($user->id)->pluck('id')->all())->toBe([$mine->id]);
});

it('casts numeric, boolean, datetime, and json columns', function (): void {
    $detail = ActivityDetail::factory()->make([
        'activity_id' => 1,
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

it('serializes start_date_local as the verbatim wall-clock, not a UTC-shifted instant', function (): void {
    // start_date_local is Strava's location wall-clock; the frontend's naive
    // parsers expect it back unshifted. The default datetime cast would convert
    // the app-tz (Asia/Jakarta) value to its UTC instant (06:20 -> 23:20 prev day).
    $detail = new ActivityDetail(['start_date_local' => '2026-01-01 06:20:30']);

    expect($detail->toArray()['start_date_local'])->toBe('2026-01-01T06:20:30');
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

it('paceSecPerKm computes pace and returns null for a zero-distance run', function (): void {
    $normal = ActivityDetail::factory()->make(['activity_id' => 1, 'distance' => 5000.0, 'moving_time' => 1500]);
    $zeroDistance = ActivityDetail::factory()->make(['activity_id' => 1, 'distance' => 0.0, 'moving_time' => 1500]);

    expect($normal->paceSecPerKm())->toBe(300.0)
        ->and($zeroDistance->paceSecPerKm())->toBeNull();
});

it('streamSummary falls back to an empty array when stream_summary is null', function (): void {
    $withSummary = ActivityDetail::factory()->make(['activity_id' => 1, 'stream_summary' => ['decoupling_pct' => 5.5]]);
    $withoutSummary = ActivityDetail::factory()->make(['activity_id' => 1, 'stream_summary' => null]);

    expect($withSummary->streamSummary())->toBe(['decoupling_pct' => 5.5])
        ->and($withoutSummary->streamSummary())->toBe([]);
});
