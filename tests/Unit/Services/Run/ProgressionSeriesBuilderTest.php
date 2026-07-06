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

beforeEach(fn () => Carbon::setTestNow('2026-05-20 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

/**
 * @return array{user: User, featured: PersonalRecord}
 */
function progressionFixture(string $category, int $valueSec, string $setAt = '2020-01-01 00:00:00'): array
{
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    // set_at defaults far outside any test's run weeks so the PR-week snap is a
    // no-op unless a test explicitly aligns it (the factory default is random).
    $featured = PersonalRecord::factory()->for($user)->create([
        'category' => $category,
        'value_sec' => $valueSec,
        'activity_id' => $activity->id,
        'set_at' => $setAt,
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
});

it('snaps the series best to the authoritative PR time so the chart matches the hero', function (): void {
    // PR time (2400s) differs from the linearly-scaled weekly best of the same
    // near-target run (10.1km/2450s => ~2426s). The chart's best point must show
    // the PR time, not the scaled approximation, so /rekor reads one number.
    // set_at is aligned to the run's week so the snap targets it.
    ['user' => $user, 'featured' => $featured] = progressionFixture('10km', 2_400, '2026-05-04 07:00:00');

    $a = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($a)->create([
        'distance' => 10_100,
        'moving_time' => 2_450,
        'start_date_local' => Carbon::parse('2026-05-04 07:00:00'),
    ]);

    $series = (new ProgressionSeriesBuilder())->build($user, $featured, 2_400);

    expect(min($series['times_sec']))->toBe(2_400)
        ->and($series['times_sec'])->toContain(2_400);
});

it('does not stamp the PR time onto a more recent week when the PR predates the window', function (): void {
    // The PR was set long before the 26-week window and hasn't been beaten since,
    // so its week isn't in the series. A newer in-window run must keep its own
    // scaled time, not get relabeled with the old PR value (the fabricated-point bug).
    ['user' => $user, 'featured' => $featured] = progressionFixture('5km', 1_500, '2024-01-01 07:00:00');

    $a = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($a)->create([
        'distance' => 5_000,
        'moving_time' => 1_550, // scales to 1550 at the 5km target; distinct from the 1500 PR
        'start_date_local' => Carbon::parse('2026-05-04 07:00:00'),
    ]);

    $series = (new ProgressionSeriesBuilder())->build($user, $featured, 1_485);

    expect($series['times_sec'])->toBe([1_550])
        ->and($series['times_sec'])->not->toContain(1_500);
});

it('keeps only the best (lowest) scaled time per week', function (): void {
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
});

it('excludes runs outside the 26-week lookback window', function (): void {
    ['user' => $user, 'featured' => $featured] = progressionFixture('5km', 1500);

    $a = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($a)->create([
        'distance' => 5_000,
        'moving_time' => 1_500,
        'start_date_local' => Carbon::parse('2025-10-01 07:00:00'), // > 26 weeks ago
    ]);

    expect((new ProgressionSeriesBuilder())->build($user, $featured, 1_485))->toBeNull();
});

it('buildMany batches multiple distance bands from a single query without cross-contamination', function (): void {
    $user = User::factory()->create();
    $act5k = Activity::factory()->for($user)->analyzed()->create();
    $pr5k = PersonalRecord::factory()->for($user)->create([
        'category' => '5km', 'value_sec' => 1_500, 'activity_id' => $act5k->id, 'set_at' => '2020-01-01',
    ]);
    $act10k = Activity::factory()->for($user)->analyzed()->create();
    $pr10k = PersonalRecord::factory()->for($user)->create([
        'category' => '10km', 'value_sec' => 2_400, 'activity_id' => $act10k->id, 'set_at' => '2020-01-01',
    ]);

    $a5 = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($a5)->create([
        'distance' => 5_000, 'moving_time' => 1_500, 'start_date_local' => Carbon::parse('2026-05-04 07:00:00'),
    ]);
    $a10 = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($a10)->create([
        'distance' => 10_000, 'moving_time' => 2_400, 'start_date_local' => Carbon::parse('2026-05-04 07:00:00'),
    ]);

    $out = (new ProgressionSeriesBuilder())->buildMany($user, [$pr5k, $pr10k], fn (): ?int => null);

    expect($out)->toHaveKeys(['5km', '10km'])
        ->and($out['5km']['times_sec'])->toBe([1_500])
        ->and($out['10km']['times_sec'])->toBe([2_400]);
});

it('buildMany preserves the given records order in the output keys', function (): void {
    $user = User::factory()->create();
    $act1 = Activity::factory()->for($user)->analyzed()->create();
    $pr10k = PersonalRecord::factory()->for($user)->create([
        'category' => '10km', 'value_sec' => 2_400, 'activity_id' => $act1->id, 'set_at' => '2020-01-01',
    ]);
    $act2 = Activity::factory()->for($user)->analyzed()->create();
    $pr5k = PersonalRecord::factory()->for($user)->create([
        'category' => '5km', 'value_sec' => 1_500, 'activity_id' => $act2->id, 'set_at' => '2020-01-01',
    ]);

    foreach ([[10_000, 2_400], [5_000, 1_500]] as [$dist, $mt]) {
        $a = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($a)->create([
            'distance' => $dist, 'moving_time' => $mt, 'start_date_local' => Carbon::parse('2026-05-04 07:00:00'),
        ]);
    }

    // Deliberately pass 10km before 5km — the output key order must follow this,
    // not e.g. alphabetical or query order.
    $out = (new ProgressionSeriesBuilder())->buildMany($user, [$pr10k, $pr5k], fn (): ?int => null);

    expect(array_keys($out))->toBe(['10km', '5km']);
});

it('buildMany omits a band with no in-window runs while keeping others', function (): void {
    $user = User::factory()->create();
    $act1 = Activity::factory()->for($user)->analyzed()->create();
    $pr5k = PersonalRecord::factory()->for($user)->create([
        'category' => '5km', 'value_sec' => 1_500, 'activity_id' => $act1->id, 'set_at' => '2020-01-01',
    ]);
    $act2 = Activity::factory()->for($user)->analyzed()->create();
    $pr10k = PersonalRecord::factory()->for($user)->create([
        'category' => '10km', 'value_sec' => 2_400, 'activity_id' => $act2->id, 'set_at' => '2020-01-01',
    ]);

    // Only the 5km band gets an in-window run; 10km has none.
    $a = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($a)->create([
        'distance' => 5_000, 'moving_time' => 1_500, 'start_date_local' => Carbon::parse('2026-05-04 07:00:00'),
    ]);

    $out = (new ProgressionSeriesBuilder())->buildMany($user, [$pr5k, $pr10k], fn (): ?int => null);

    expect($out)->toHaveKey('5km')
        ->and($out)->not->toHaveKey('10km');
});

it('buildMany resolves each band\'s goal independently via the resolver callback', function (): void {
    $user = User::factory()->create();
    $act1 = Activity::factory()->for($user)->analyzed()->create();
    $pr5k = PersonalRecord::factory()->for($user)->create([
        'category' => '5km', 'value_sec' => 1_500, 'activity_id' => $act1->id, 'set_at' => '2020-01-01',
    ]);
    $act2 = Activity::factory()->for($user)->analyzed()->create();
    $pr10k = PersonalRecord::factory()->for($user)->create([
        'category' => '10km', 'value_sec' => 2_400, 'activity_id' => $act2->id, 'set_at' => '2020-01-01',
    ]);

    foreach ([[5_000, 1_500], [10_000, 2_400]] as [$dist, $mt]) {
        $a = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($a)->create([
            'distance' => $dist, 'moving_time' => $mt, 'start_date_local' => Carbon::parse('2026-05-04 07:00:00'),
        ]);
    }

    $out = (new ProgressionSeriesBuilder())->buildMany(
        $user,
        [$pr5k, $pr10k],
        fn (PersonalRecord $r): int => $r->category->value === '5km' ? 1_485 : 2_350,
    );

    expect($out['5km']['goal_sec'])->toBe(1_485)
        ->and($out['10km']['goal_sec'])->toBe(2_350);
});

it('ignores another user\'s runs and un-analyzed activities', function (): void {
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
});
