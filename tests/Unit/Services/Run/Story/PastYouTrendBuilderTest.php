<?php

declare(strict_types=1);

use App\Enums\TrendVerdict;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Story\PastYouTrend;
use App\Services\Run\Story\PastYouTrendBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-15 09:00:00');
});

/**
 * Every run is the same 10 km on the same profile at the same hour, so the only
 * thing that varies between a pair is the thing under test.
 *
 * @param  array<string, mixed>  $overrides
 */
function trendRun(User $user, int $daysAgo, int $movingTimeSec, array $overrides = [], bool $summaryOnly = false): ActivityDetail
{
    $factory = Activity::factory()->for($user);
    $activity = ($summaryOnly ? $factory->summaryOnly() : $factory)->create();

    return ActivityDetail::factory()->for($activity)->create(array_merge([
        'distance' => 10_000.0,
        'moving_time' => $movingTimeSec,
        'elapsed_time' => $movingTimeSec,
        'total_elevation_gain' => 50.0,
        'average_heartrate' => 155.0,
        'start_date_local' => Carbon::today()->subDays($daysAgo)->setTime(6, 0),
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function summaryOnlyRun(User $user, int $daysAgo, int $movingTimeSec, array $overrides = []): ActivityDetail
{
    return trendRun($user, $daysAgo, $movingTimeSec, array_merge([
        'trimp_edwards' => null,
        'stream_summary' => null,
        'splits_metric' => null,
        'laps' => null,
        'calories' => null,
        'weather_temp_c' => null,
        'weather_humidity_pct' => null,
    ], $overrides), summaryOnly: true);
}

function buildTrend(User $user): PastYouTrend
{
    return app(PastYouTrendBuilder::class)->build($user);
}

/** Rows that exist only to crowd the history, comparable to nothing. */
function crowdHistory(User $user, int $count, int $fromDaysAgo, int $toDaysAgo): void
{
    $now = Carbon::now()->toDateTimeString();
    $span = $fromDaysAgo - $toDaysAgo;

    $activities = [];
    for ($i = 0; $i < $count; $i++) {
        $activities[] = [
            'user_id' => $user->id,
            'strava_external_id' => 900_000 + $i,
            'fetched_at' => $now,
            'analyzed_at' => $now,
            'ingest_state' => 'detailed',
            'detail_fail_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::table('activities')->insert($activities);

    $ids = DB::table('activities')
        ->where('user_id', $user->id)
        ->whereBetween('strava_external_id', [900_000, 900_000 + $count - 1])
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $details = [];
    foreach ($ids as $i => $id) {
        $details[] = [
            'activity_id' => $id,
            'name' => 'Crowd',
            'start_date_local' => Carbon::today()->subDays($toDaysAgo + $i % $span)->setTime(6, 0)->toDateTimeString(),
            'distance' => 20_000.0,
            'moving_time' => 8_700,
            'elapsed_time' => 8_700,
            'total_elevation_gain' => 100.0,
            'has_heartrate' => 1,
            'average_heartrate' => 155.0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::table('activity_details')->insert($details);
}

it('calls it improving when the recent runs are faster than their matches', function (): void {
    $user = User::factory()->create();
    foreach ([200, 215, 230, 245] as $daysAgo) {
        trendRun($user, $daysAgo, 4_400);
    }
    foreach ([3, 10, 17, 24] as $daysAgo) {
        trendRun($user, $daysAgo, 4_300);
    }

    $trend = buildTrend($user);

    expect($trend->verdict)->toBe(TrendVerdict::Improving)
        ->and($trend->comparisons)->toHaveCount(4)
        ->and($trend->meanPaceDeltaSec)->toBe(10.0)
        ->and($trend->windowDays)->toBe(PastYouTrendBuilder::WINDOW_DAYS);
});

it('calls it slipped when the recent runs are slower than their matches', function (): void {
    $user = User::factory()->create();
    foreach ([200, 215, 230, 245] as $daysAgo) {
        trendRun($user, $daysAgo, 4_300);
    }
    foreach ([3, 10, 17, 24] as $daysAgo) {
        trendRun($user, $daysAgo, 4_400);
    }

    $trend = buildTrend($user);

    expect($trend->verdict)->toBe(TrendVerdict::Slipped)
        ->and($trend->meanPaceDeltaSec)->toBe(-10.0)
        ->and(array_map(
            fn ($comparison): string => $comparison->direction()->value,
            $trend->comparisons,
        ))->toBe(['worse', 'worse', 'worse', 'worse']);
});

it('calls it plateaued when every pair lands inside the noise band', function (): void {
    $user = User::factory()->create();
    foreach ([200, 215, 230, 245] as $daysAgo) {
        trendRun($user, $daysAgo, 4_352);
    }
    foreach ([3, 10, 17, 24] as $daysAgo) {
        trendRun($user, $daysAgo, 4_350);
    }

    $trend = buildTrend($user);

    expect($trend->verdict)->toBe(TrendVerdict::Plateaued)
        ->and($trend->comparisons)->toHaveCount(4);
});

it('calls it plateaued when the pairs disagree with each other', function (): void {
    $user = User::factory()->create();
    foreach ([200, 215, 230, 245] as $daysAgo) {
        trendRun($user, $daysAgo, 4_400);
    }
    trendRun($user, 3, 4_300);
    trendRun($user, 10, 4_300);
    trendRun($user, 17, 4_480);
    trendRun($user, 24, 4_480);

    expect(buildTrend($user)->verdict)->toBe(TrendVerdict::Plateaued);
});

it('will not call it improving when one bad pair outweighs a majority of small gains', function (): void {
    $user = User::factory()->create();
    foreach ([200, 215, 230] as $daysAgo) {
        trendRun($user, $daysAgo, 4_100);
    }
    trendRun($user, 3, 4_040);
    trendRun($user, 10, 4_040);
    trendRun($user, 17, 4_400);

    $trend = buildTrend($user);

    expect($trend->verdict)->toBe(TrendVerdict::Plateaued)
        ->and($trend->meanPaceDeltaSec)->toBeLessThan(0.0);
});

it('calls it improving on heart rate alone when pace held', function (): void {
    $user = User::factory()->create();
    foreach ([200, 215, 230, 245] as $daysAgo) {
        trendRun($user, $daysAgo, 4_350, ['average_heartrate' => 162.0]);
    }
    foreach ([3, 10, 17, 24] as $daysAgo) {
        trendRun($user, $daysAgo, 4_350, ['average_heartrate' => 150.0]);
    }

    $trend = buildTrend($user);

    expect($trend->verdict)->toBe(TrendVerdict::Improving)
        ->and($trend->meanPaceDeltaSec)->toBe(0.0)
        ->and($trend->meanHrDeltaBpm)->toBe(-12.0);
});

it('calls it slipped on heart rate alone when pace held', function (): void {
    $user = User::factory()->create();
    foreach ([200, 215, 230, 245] as $daysAgo) {
        trendRun($user, $daysAgo, 4_350, ['average_heartrate' => 150.0]);
    }
    foreach ([3, 10, 17, 24] as $daysAgo) {
        trendRun($user, $daysAgo, 4_350, ['average_heartrate' => 162.0]);
    }

    expect(buildTrend($user)->verdict)->toBe(TrendVerdict::Slipped);
});

it('matches runs that are still summary-only, with no streams to read', function (): void {
    $user = User::factory()->create();
    foreach ([200, 215, 230, 245] as $daysAgo) {
        summaryOnlyRun($user, $daysAgo, 4_400);
    }
    foreach ([3, 10, 17, 24] as $daysAgo) {
        summaryOnlyRun($user, $daysAgo, 4_300);
    }

    $trend = buildTrend($user);
    $payload = $trend->toArray();

    expect($trend->verdict)->toBe(TrendVerdict::Improving)
        ->and($trend->comparisons)->toHaveCount(4)
        ->and(collect($payload['comparisons'])->pluck('current.ingest_state')->unique()->all())->toBe(['summary'])
        ->and(collect($payload['comparisons'])->pluck('past.ingest_state')->unique()->all())->toBe(['summary'])
        ->and($payload['fitness_delta_ctl'])->toBeNull()
        ->and($payload['pace_consistency_now'])->toBeNull();
});

it('reports the empty state when nothing in history is comparable', function (): void {
    $user = User::factory()->create();
    foreach ([3, 10, 17] as $daysAgo) {
        trendRun($user, $daysAgo, 4_300);
    }

    $trend = buildTrend($user);

    expect($trend->verdict)->toBe(TrendVerdict::NotEnoughHistory)
        ->and($trend->verdict->isJudged())->toBeFalse()
        ->and($trend->comparisons)->toBe([])
        ->and($trend->meanPaceDeltaSec)->toBeNull();
});

it('reports the empty state when only one pair matched', function (): void {
    $user = User::factory()->create();
    trendRun($user, 200, 4_400);
    trendRun($user, 3, 4_300);

    $trend = buildTrend($user);

    expect($trend->verdict)->toBe(TrendVerdict::NotEnoughHistory)
        ->and($trend->comparisons)->toHaveCount(1);
});

it('caps the evidence it collects', function (): void {
    $user = User::factory()->create();
    foreach ([180, 195, 210, 225, 240, 255] as $daysAgo) {
        trendRun($user, $daysAgo, 4_400);
    }
    foreach ([2, 8, 14, 20, 26, 32] as $daysAgo) {
        trendRun($user, $daysAgo, 4_300);
    }

    expect(buildTrend($user)->comparisons)->toHaveCount(PastYouTrendBuilder::MAX_COMPARISONS);
});

it('never uses the same past run as evidence twice', function (): void {
    $user = User::factory()->create();
    trendRun($user, 200, 4_400);
    trendRun($user, 215, 4_400);
    foreach ([3, 10, 17, 24] as $daysAgo) {
        trendRun($user, $daysAgo, 4_300);
    }

    $trend = buildTrend($user);
    $pastIds = array_map(fn ($comparison): int => $comparison->past->activityId, $trend->comparisons);

    expect($trend->comparisons)->toHaveCount(2)
        ->and($pastIds)->toHaveCount(count(array_unique($pastIds)));
});

it('never reaches into another runner\'s history', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    foreach ([200, 215, 230, 245] as $daysAgo) {
        trendRun($other, $daysAgo, 4_400);
    }
    foreach ([3, 10, 17, 24] as $daysAgo) {
        trendRun($user, $daysAgo, 4_300);
    }

    expect(buildTrend($user)->verdict)->toBe(TrendVerdict::NotEnoughHistory);
});

it('never matches a not-yet-analyzed activity as a candidate', function (): void {
    $user = User::factory()->create();
    foreach ([3, 10, 17, 24] as $daysAgo) {
        trendRun($user, $daysAgo, 4_300);
    }
    // Would-be candidates for the recent runs above, but stuck as un-ingested
    // stubs — none of them may be picked as a match.
    foreach ([200, 215, 230, 245] as $daysAgo) {
        $stub = Activity::factory()->for($user)->stub()->create();
        ActivityDetail::factory()->for($stub)->create([
            'distance' => 10_000.0,
            'moving_time' => 4_300,
            'elapsed_time' => 4_300,
            'start_date_local' => Carbon::today()->subDays($daysAgo)->setTime(6, 0),
        ]);
    }

    expect(buildTrend($user)->verdict)->toBe(TrendVerdict::NotEnoughHistory);
});

it('still finds the oldest comparable runs when the window holds hundreds of others', function (): void {
    $user = User::factory()->create();
    foreach ([350, 355, 360, 365] as $daysAgo) {
        trendRun($user, $daysAgo, 4_400);
    }
    crowdHistory($user, 400, fromDaysAgo: 340, toDaysAgo: 45);
    foreach ([3, 10, 17, 24] as $daysAgo) {
        trendRun($user, $daysAgo, 4_300);
    }

    $trend = buildTrend($user);

    expect($trend->verdict)->toBe(TrendVerdict::Improving)
        ->and($trend->comparisons)->toHaveCount(4)
        ->and(array_map(
            fn ($comparison): int => (int) $comparison->past->startedAt->diffInDays(Carbon::today()),
            $trend->comparisons,
        ))->each->toBeGreaterThan(340);
});

it('ignores history older than the matcher\'s ceiling', function (): void {
    $user = User::factory()->create();
    foreach ([380, 395] as $daysAgo) {
        trendRun($user, $daysAgo, 4_400);
    }
    foreach ([3, 10] as $daysAgo) {
        trendRun($user, $daysAgo, 4_300);
    }

    expect(buildTrend($user)->verdict)->toBe(TrendVerdict::NotEnoughHistory);
});

it('reports the fitness trend beside the verdict when the detail pipeline has caught up', function (): void {
    $user = User::factory()->create();
    foreach ([200, 215, 230, 245] as $daysAgo) {
        trendRun($user, $daysAgo, 4_400, ['trimp_edwards' => 60.0]);
    }
    foreach ([3, 10, 17, 24] as $daysAgo) {
        trendRun($user, $daysAgo, 4_300, ['trimp_edwards' => 120.0]);
    }

    expect(buildTrend($user)->fitnessDeltaCtl)->toBeFloat()->toBeGreaterThan(0.0);
});

it('serves the payload from cache for the rest of the athlete\'s day', function (): void {
    $user = User::factory()->create();
    foreach ([200, 215, 230, 245] as $daysAgo) {
        trendRun($user, $daysAgo, 4_400);
    }
    foreach ([3, 10, 17, 24] as $daysAgo) {
        trendRun($user, $daysAgo, 4_300);
    }
    $builder = app(PastYouTrendBuilder::class);

    $first = $builder->payload($user);
    ActivityDetail::query()->delete();

    expect($builder->payload($user))->toBe($first)
        ->and($first['verdict'])->toBe(TrendVerdict::Improving->value);
});

it('keys the cache by runner and by day', function (): void {
    $user = User::factory()->create();
    trendRun($user, 200, 4_400);
    trendRun($user, 3, 4_300);

    app(PastYouTrendBuilder::class)->payload($user);

    expect(Cache::has(PastYouTrendBuilder::cacheKey($user->id, Carbon::today()->toDateString())))->toBeTrue()
        ->and(Cache::has(PastYouTrendBuilder::cacheKey($user->id, Carbon::tomorrow()->toDateString())))->toBeFalse()
        ->and(Cache::has(PastYouTrendBuilder::cacheKey($user->id + 1, Carbon::today()->toDateString())))->toBeFalse();
});

it('drops only the day it is asked to drop', function (): void {
    $user = User::factory()->create();
    $today = PastYouTrendBuilder::cacheKey($user->id, Carbon::today()->toDateString());
    $yesterday = PastYouTrendBuilder::cacheKey($user->id, Carbon::yesterday()->toDateString());
    Cache::put($today, ['verdict' => 'stale']);
    Cache::put($yesterday, ['verdict' => 'stale']);

    PastYouTrendBuilder::clearCache($user);

    expect(Cache::has($today))->toBeFalse()
        ->and(Cache::has($yesterday))->toBeTrue();
});
