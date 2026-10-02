<?php

declare(strict_types=1);

use App\Models\ActivityDetail;
use App\Services\Run\Metrics\PerceivedEffort;
use Illuminate\Support\Carbon;

function perceivedDetail(array $attributes = []): ActivityDetail
{
    return new ActivityDetail([
        'activity_id' => 7,
        'start_date_local' => Carbon::parse('2026-06-10 06:00:00'),
        'moving_time' => 2400,
        'has_heartrate' => false,
        'perceived_effort' => null,
        ...$attributes,
    ]);
}

it('turns a score into load as score times moving minutes over two', function (): void {
    expect(PerceivedEffort::load(perceivedDetail(['perceived_effort' => 6])))->toBe(120.0)
        ->and(PerceivedEffort::load(perceivedDetail(['perceived_effort' => 3, 'moving_time' => 1800])))->toBe(45.0);
});

it('gives no load to a run without a score', function (): void {
    expect(PerceivedEffort::load(perceivedDetail()))->toBeNull();
});

it('gives no load once the run carries heart rate, while keeping the stored score', function (): void {
    $detail = perceivedDetail(['perceived_effort' => 6, 'has_heartrate' => true]);

    expect(PerceivedEffort::load($detail))->toBeNull()
        ->and($detail->perceived_effort)->toBe(6);
});

it('gives no load without a moving time', function (): void {
    expect(PerceivedEffort::load(perceivedDetail(['perceived_effort' => 6, 'moving_time' => null])))->toBeNull()
        ->and(PerceivedEffort::load(perceivedDetail(['perceived_effort' => 6, 'moving_time' => 0])))->toBeNull();
});

it('accepts a score only within 72 hours of the run start', function (): void {
    $detail = perceivedDetail();
    $start = Carbon::parse('2026-06-10 06:00:00');

    expect(PerceivedEffort::accepts($detail, $start->copy()->addHours(71)->addMinutes(59)))->toBeTrue()
        ->and(PerceivedEffort::accepts($detail, $start->copy()->addHours(72)))->toBeFalse();
});

it('reads the window from the true start instant when the run carries one', function (): void {
    $detail = perceivedDetail([
        'start_date_local' => Carbon::parse('2026-06-10 13:00:00'),
        'start_date_utc' => Carbon::parse('2026-06-10 06:00:00'),
    ]);

    expect(PerceivedEffort::accepts($detail, Carbon::parse('2026-06-13 06:00:00', 'UTC')->subMinute()))->toBeTrue()
        ->and(PerceivedEffort::accepts($detail, Carbon::parse('2026-06-13 06:00:00', 'UTC')))->toBeFalse();
});

it('never accepts a score on a run with heart rate or without a start', function (): void {
    $now = Carbon::parse('2026-06-10 08:00:00');

    expect(PerceivedEffort::accepts(perceivedDetail(['has_heartrate' => true]), $now))->toBeFalse()
        ->and(PerceivedEffort::accepts(perceivedDetail(['start_date_local' => null]), $now))->toBeFalse();
});

it('offers the prompt with the saved score while the window is open, and nothing after', function (): void {
    $detail = perceivedDetail(['perceived_effort' => 4, 'name' => 'Treadmill']);

    expect(PerceivedEffort::prompt($detail, Carbon::parse('2026-06-11 06:00:00')))->toBe([
        'activity_id' => 7,
        'score' => 4,
        'name' => 'Treadmill',
        'start_date_local' => '2026-06-10T06:00:00',
    ])
        ->and(PerceivedEffort::prompt($detail, Carbon::parse('2026-06-13 06:00:00')))->toBeNull();
});
