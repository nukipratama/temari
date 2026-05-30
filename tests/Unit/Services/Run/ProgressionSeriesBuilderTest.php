<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\ProgressionSeriesBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, featured: PersonalRecord}
 */
function progressionFixture(string $category, int $valueSec): array
{
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $featured = PersonalRecord::factory()->for($user)->create([
        'category' => $category,
        'value_sec' => $valueSec,
        'activity_id' => $activity->id,
    ]);

    return ['user' => $user, 'featured' => $featured];
}

it('returns null for an effort (non-distance) PR category', function (): void {
    ['user' => $user, 'featured' => $featured] = progressionFixture('best_20min', 320);

    expect((new ProgressionSeriesBuilder())->build($user, $featured, null))->toBeNull();
});

it('returns null when there are no in-window runs in the distance bucket', function (): void {
    ['user' => $user, 'featured' => $featured] = progressionFixture('5km', 1500);

    expect((new ProgressionSeriesBuilder())->build($user, $featured, 1485))->toBeNull();
});

it('builds the weekly-best series scaled to the target distance', function (): void {
    Carbon::setTestNow('2026-05-20 12:00:00');
    ['user' => $user, 'featured' => $featured] = progressionFixture('10km', 2400);

    // Two runs in different ISO weeks, each within the +/-5% bucket of 10km.
    foreach ([['2026-04-12', 9_900, 2_500], ['2026-05-04', 10_100, 2_450]] as [$date, $dist, $mt]) {
        $a = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($a)->create([
            'distance' => $dist,
            'moving_time' => $mt,
            'start_date_local' => Carbon::parse($date.' 07:00:00'),
        ]);
    }

    $series = (new ProgressionSeriesBuilder())->build($user, $featured, 2_400);

    expect($series)->not->toBeNull()
        ->and($series['category'])->toBe('10km')
        ->and($series['weeks'])->toHaveCount(2)
        ->and($series['times_sec'])->toHaveCount(2)
        ->and($series['goal_sec'])->toBe(2_400);

    // First week: 2500s over 9.9km scaled to 10km = round(2500 * 10000/9900).
    expect($series['times_sec'][0])->toBe((int) round(2_500 * (10_000 / 9_900)));

    Carbon::setTestNow();
});

it('keeps only the best (lowest) scaled time per week', function (): void {
    Carbon::setTestNow('2026-05-20 12:00:00');
    ['user' => $user, 'featured' => $featured] = progressionFixture('5km', 1500);

    // Two runs in the SAME ISO week; the faster scaled time should win.
    foreach ([1_600, 1_500] as $mt) {
        $a = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($a)->create([
            'distance' => 5_000,
            'moving_time' => $mt,
            'start_date_local' => Carbon::parse('2026-05-04 07:00:00'),
        ]);
    }

    $series = (new ProgressionSeriesBuilder())->build($user, $featured, 1_485);

    expect($series['weeks'])->toHaveCount(1)
        ->and($series['times_sec'][0])->toBe(1_500);

    Carbon::setTestNow();
});

it('excludes runs outside the 26-week lookback window', function (): void {
    Carbon::setTestNow('2026-05-20 12:00:00');
    ['user' => $user, 'featured' => $featured] = progressionFixture('5km', 1500);

    $a = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($a)->create([
        'distance' => 5_000,
        'moving_time' => 1_500,
        'start_date_local' => Carbon::parse('2025-10-01 07:00:00'), // > 26 weeks ago
    ]);

    expect((new ProgressionSeriesBuilder())->build($user, $featured, 1_485))->toBeNull();

    Carbon::setTestNow();
});

it('ignores another user\'s runs and un-analyzed activities', function (): void {
    Carbon::setTestNow('2026-05-20 12:00:00');
    ['user' => $user, 'featured' => $featured] = progressionFixture('5km', 1500);

    $other = User::factory()->create();
    $otherActivity = Activity::factory()->for($other)->analyzed()->create();
    ActivityDetail::factory()->for($otherActivity)->create([
        'distance' => 5_000,
        'moving_time' => 1_400,
        'start_date_local' => Carbon::parse('2026-05-04 07:00:00'),
    ]);

    // Own run but not analyzed.
    $pending = Activity::factory()->for($user)->create(['analyzed_at' => null]);
    ActivityDetail::factory()->for($pending)->create([
        'distance' => 5_000,
        'moving_time' => 1_450,
        'start_date_local' => Carbon::parse('2026-05-04 07:00:00'),
    ]);

    expect((new ProgressionSeriesBuilder())->build($user, $featured, 1_485))->toBeNull();

    Carbon::setTestNow();
});
