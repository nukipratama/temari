<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\ThresholdEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function seedDetail(User $user, array $summary, ?Carbon $startDate = null): void
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $startDate ?? Carbon::today()->subDays(7),
        'stream_summary' => $summary,
    ]);
}

it('returns null when no hard sessions exist in the lookback window', function (): void {
    $user = User::factory()->create();
    // Easy run: most time in Z2, very little Z3+
    seedDetail($user, [
        'time_in_zone_pct' => ['Z2' => 80.0, 'Z3' => 10.0, 'Z4' => 0.0],
        'best_60min_pace' => '6:30',
    ]);

    expect((new ThresholdEstimator())->estimate($user))->toBeNull();
});

it('uses best_60min_pace from a single hard session with low confidence', function (): void {
    $user = User::factory()->create();
    seedDetail($user, [
        'time_in_zone_pct' => ['Z2' => 20.0, 'Z3' => 50.0, 'Z4' => 25.0, 'Z5' => 5.0],
        'best_60min_pace' => '5:00', // 300 sec/km
    ]);

    $result = (new ThresholdEstimator())->estimate($user);

    expect($result)->not->toBeNull()
        ->and($result['pace_sec'])->toEqualWithDelta(300.0, 0.1)
        ->and($result['confidence'])->toBe('low')
        ->and($result['sample_size'])->toBe(1);
});

it('falls back to best_30min_pace when 60min not available', function (): void {
    $user = User::factory()->create();
    seedDetail($user, [
        'time_in_zone_pct' => ['Z3' => 40.0, 'Z4' => 30.0],
        'best_30min_pace' => '5:30',
    ]);

    $result = (new ThresholdEstimator())->estimate($user);

    expect($result['pace_sec'])->toEqualWithDelta(330.0, 0.1);
});

it('takes the median across multiple hard sessions and reports confidence', function (): void {
    $user = User::factory()->create();
    foreach (['5:00', '5:10', '5:20', '5:30', '5:40', '5:50'] as $pace) {
        seedDetail($user, [
            'time_in_zone_pct' => ['Z3' => 40.0, 'Z4' => 30.0],
            'best_60min_pace' => $pace,
        ]);
    }

    $result = (new ThresholdEstimator())->estimate($user);

    // 6 values [300, 310, 320, 330, 340, 350] → median index floor(5/2)=2 → 320
    expect($result['pace_sec'])->toEqualWithDelta(320.0, 0.1)
        ->and($result['confidence'])->toBe('high')
        ->and($result['sample_size'])->toBe(6);
});

it('ignores sessions outside the 60-day lookback', function (): void {
    $user = User::factory()->create();
    seedDetail($user, [
        'time_in_zone_pct' => ['Z3' => 60.0],
        'best_60min_pace' => '5:00',
    ], Carbon::today()->subDays(120)); // too old

    expect((new ThresholdEstimator())->estimate($user))->toBeNull();
});

it('ignores stream summaries whose time_in_zone_pct is not an array', function (): void {
    $user = User::factory()->create();
    seedDetail($user, [
        'time_in_zone_pct' => 'malformed-string-not-array',
        'best_60min_pace' => '5:00',
    ]);

    expect((new ThresholdEstimator())->estimate($user))->toBeNull();
});

it('ignores stream summaries that have neither 30min nor 60min best paces', function (): void {
    $user = User::factory()->create();
    seedDetail($user, [
        'time_in_zone_pct' => ['Z3' => 60.0],
        'best_5min_pace' => '4:30',
        // No best_30min_pace or best_60min_pace
    ]);

    expect((new ThresholdEstimator())->estimate($user))->toBeNull();
});
