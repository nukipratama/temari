<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\Gamification\UnlockEngine;
use App\Services\Run\Metrics\PersonalRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    // UnlockEngine::grantEligible does a live DB query on every PR break and its
    // own behavior has a dedicated suite (UnlockEngineTest); nothing here asserts
    // on unlocks, so it's faked to keep this file scoped to PR-detection logic.
    $unlockEngine = Mockery::mock(UnlockEngine::class);
    $unlockEngine->shouldReceive('grantEligible')->andReturn([]);
    $this->app->instance(UnlockEngine::class, $unlockEngine);
    $this->records = app(PersonalRecords::class);
});

/**
 * @return list<array{split: int, distance: int, elapsed_time: int, moving_time: int}>
 */
function evenSplits(int $count, int $movingTime): array
{
    $splits = [];
    for ($km = 1; $km <= $count; $km++) {
        // elapsed_time padded above moving_time so a regression back to
        // elapsed_time would change the computed PR and fail the assertions.
        $splits[] = [
            'split' => $km,
            'distance' => 1000,
            'elapsed_time' => $movingTime + 60,
            'moving_time' => $movingTime,
        ];
    }

    return $splits;
}

it('interpolates time at distance from splits (no walk-past inflation)', function (): void {
    // Half-marathon hit mid-run; later walk splits must not inflate the PR.
    $splits = [];
    for ($km = 1; $km <= 21; $km++) {
        $splits[] = ['split' => $km, 'distance' => 1000.0, 'moving_time' => 480.0];
    }
    $splits[] = ['split' => 22, 'distance' => 1000.0, 'moving_time' => 900.0];
    $splits[] = ['split' => 23, 'distance' => 1000.0, 'moving_time' => 900.0];
    $splits[] = ['split' => 24, 'distance' => 1000.0, 'moving_time' => 900.0];
    $splits[] = ['split' => 25, 'distance' => 1000.0, 'moving_time' => 900.0];

    // 21 km × 480s + 97.5m of the slow km 22 ≈ 10167.75s.
    $secs = $this->records->timeAtDistance($splits, 21097.5);

    expect($secs)->toBeFloat()->toEqualWithDelta(10167.75, 1.0);
});

it('records the fastest embedded window, not the opening segment, on a negative-split run', function (): void {
    // Slow first 5 km (400s/km) then a fast closing 5 km (300s/km). The opening
    // 5 km is 2000s; the genuine best 5 km is the closing window at 1500s.
    $splits = [];
    for ($km = 1; $km <= 5; $km++) {
        $splits[] = ['split' => $km, 'distance' => 1000.0, 'moving_time' => 400.0];
    }
    for ($km = 6; $km <= 10; $km++) {
        $splits[] = ['split' => $km, 'distance' => 1000.0, 'moving_time' => 300.0];
    }

    $secs = $this->records->timeAtDistance($splits, 5000.0);

    expect($secs)->toBeFloat()->toEqualWithDelta(1500.0, 0.01);
});

it('uses moving_time, not elapsed_time, so paused seconds do not inflate the PR', function (): void {
    // A paused run: each km took 600s moving but 900s elapsed (5 min of pauses).
    // The PR must reflect the 5-km moving time (3000s), not elapsed (4500s).
    $splits = [];
    for ($km = 1; $km <= 5; $km++) {
        $splits[] = ['split' => $km, 'distance' => 1000.0, 'moving_time' => 600.0, 'elapsed_time' => 900.0];
    }

    $secs = $this->records->timeAtDistance($splits, 5000.0);

    expect($secs)->toBeFloat()->toEqualWithDelta(3000.0, 0.01);
});

it('returns null when splits do not reach the target distance', function (): void {
    $splits = [
        ['split' => 1, 'distance' => 1000, 'moving_time' => 400],
        ['split' => 2, 'distance' => 1000, 'moving_time' => 410],
    ];

    expect($this->records->timeAtDistance($splits, 10_000))->toBeNull();
});

it('inserts a fresh distance PR when none exists', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 6000,
        'splits_metric' => evenSplits(6, 380),
        'stream_summary' => null,
    ]);

    $broken = $this->records->detectAndStore($activity, $detail);

    expect($broken)->toContain('5km')
        ->and(PersonalRecord::query()->where([
            'user_id' => $user->id,
            'category' => '5km',
        ])->first())->not->toBeNull();
});

it('does not break a PR when the new time is slower', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1500.0,
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'splits_metric' => evenSplits(5, 360),
        'stream_summary' => null,
    ]);

    $broken = $this->records->detectAndStore($activity, $detail);

    expect($broken)->not->toContain('5km');
});

it('breaks an effort PR when stream_summary has a faster best-N pace', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => 'best_5min',
        'value_sec' => 320.0,
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'splits_metric' => [],
        'stream_summary' => [
            'best_5min_pace' => '5:00',
        ],
    ]);

    $broken = $this->records->detectAndStore($activity, $detail);

    expect($broken)->toContain('best_5min')
        ->and(PersonalRecord::query()->where([
            'user_id' => $user->id,
            'category' => 'best_5min',
        ])->value('value_sec'))->toBe(300.0);
});

it('ignores effort pace strings that do not match M:SS format', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'splits_metric' => [],
        'stream_summary' => [
            'best_5min_pace' => 'not-a-pace',
            'best_10min_pace' => '5:00',
        ],
    ]);

    $broken = $this->records->detectAndStore($activity, $detail);

    expect($broken)->toContain('best_10min')
        ->and($broken)->not->toContain('best_5min');
});

it('respects per-user scoping (PR break for user A does not affect user B)', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    PersonalRecord::factory()->for($userB)->create([
        'category' => '5km',
        'value_sec' => 1500.0,
    ]);

    $activity = Activity::factory()->for($userA)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'splits_metric' => evenSplits(5, 380),
        'stream_summary' => null,
    ]);

    $broken = $this->records->detectAndStore($activity, $detail);

    expect($broken)->toContain('5km')
        ->and(PersonalRecord::query()->where('user_id', $userB->id)->where('category', '5km')->value('value_sec'))
        ->toBe(1500.0);
});

it('requests pr_context with invalidate:false so a backfill does not re-bill each beat', function (): void {
    $mock = $this->mock(AnalysisService::class);
    $mock->shouldReceive('request')
        ->atLeast()->once()
        // Positional matcher (named-arg with() trips a Mockery quirk): request is
        // (subjectOrType, subjectId, type, discriminator, delaySeconds, invalidate).
        ->withArgs(fn (...$args): bool => ($args[0] ?? null) === PersonalRecord::class
            && ($args[2] ?? null) === AnalysisType::PrContext
            && ($args[5] ?? true) === false)
        ->andReturn(new Analysis());

    $records = app(PersonalRecords::class);

    $user = User::factory()->create();
    // A single 5km record so exactly one category beats and pr_context is requested.
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1500.0,
    ]);
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'splits_metric' => evenSplits(5, 280),
        'stream_summary' => null,
    ]);

    expect($records->detectAndStore($activity, $detail))->toContain('5km');
});

it('rebuildForUser drops orphaned records and re-detects from surviving runs', function (): void {
    $user = User::factory()->create();

    // A stale, orphaned record (activity_id nulled after a delete) claiming an
    // unbeatable 1km time that no surviving run can match.
    PersonalRecord::factory()->for($user)->create([
        'category' => '1km',
        'value_sec' => 200.0,
        'activity_id' => null,
    ]);

    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'splits_metric' => evenSplits(5, 300),
        'start_date_local' => now(),
    ]);

    $this->records->rebuildForUser($user);

    $km = PersonalRecord::query()->where('user_id', $user->id)->where('category', '1km')->first();
    expect($km)->not->toBeNull()
        ->and($km->value_sec)->toEqualWithDelta(300.0, 0.01)
        ->and($km->activity_id)->toBe($activity->id);
});
