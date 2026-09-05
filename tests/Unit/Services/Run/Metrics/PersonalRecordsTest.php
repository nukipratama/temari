<?php

declare(strict_types=1);

use App\Actions\Gamification\GrantEligibleUnlocksAction;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\PersonalRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    $unlockEngine = Mockery::mock(GrantEligibleUnlocksAction::class);
    $unlockEngine->shouldReceive('__invoke')->andReturn([]);
    $this->app->instance(GrantEligibleUnlocksAction::class, $unlockEngine);
    $this->records = app(PersonalRecords::class);
});

/**
 * `stream_summary.per_km` rows as KmSplitBuilder writes them.
 *
 * @return list<array{km: int, pace: string, elapsed_sec: int, distance_m: int}>
 */
function evenPerKm(int $count, int $elapsedSec): array
{
    $rows = [];
    for ($km = 1; $km <= $count; $km++) {
        $rows[] = [
            'km' => $km,
            'pace' => PaceFormatter::format((float) $elapsedSec),
            'elapsed_sec' => $elapsedSec,
            'distance_m' => 1000,
        ];
    }

    return $rows;
}

it('interpolates time at distance from splits (no walk-past inflation)', function (): void {
    // Half-marathon hit mid-run; later walk splits must not inflate the PR.
    $splits = evenPerKm(21, 480);
    for ($km = 22; $km <= 25; $km++) {
        $splits[] = ['km' => $km, 'pace' => '15:00', 'elapsed_sec' => 900, 'distance_m' => 1000];
    }

    // 21 km × 480s + 97.5m of the slow km 22 ≈ 10167.75s.
    $secs = $this->records->timeAtDistance($splits, 21097.5);

    expect($secs)->toBeFloat()->toEqualWithDelta(10167.75, 1.0);
});

it('records the fastest embedded window, not the opening segment, on a negative-split run', function (): void {
    // Slow first 5 km (400s/km) then a fast closing 5 km (300s/km). The opening
    // 5 km is 2000s; the genuine best 5 km is the closing window at 1500s.
    $splits = [
        ...evenPerKm(5, 400),
        ...evenPerKm(5, 300),
    ];

    $secs = $this->records->timeAtDistance($splits, 5000.0);

    expect($secs)->toBeFloat()->toEqualWithDelta(1500.0, 0.01);
});

it('reads elapsed_sec, so paused seconds count toward the PR like the watch counts them', function (): void {
    // A paused run: each km took 600s moving but 900s elapsed. Strava's own
    // moving_time is no longer an input, so the 5 km PR is the elapsed 4500s.
    $splits = [];
    for ($km = 1; $km <= 5; $km++) {
        $splits[] = ['km' => $km, 'pace' => '15:00', 'elapsed_sec' => 900, 'moving_time' => 600, 'distance_m' => 1000];
    }

    $secs = $this->records->timeAtDistance($splits, 5000.0);

    expect($secs)->toBeFloat()->toEqualWithDelta(4500.0, 0.01);
});

it('returns null when splits do not reach the target distance', function (): void {
    expect($this->records->timeAtDistance(evenPerKm(2, 400), 10_000))->toBeNull();
});

it('inserts a fresh distance PR when none exists', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 6000,
        'stream_summary' => ['per_km' => evenPerKm(6, 380)],
    ]);

    $broken = $this->records->detectAndStore($activity, $detail);

    expect($broken)->toContain('5km')
        ->and(PersonalRecord::query()->where([
            'user_id' => $user->id,
            'category' => '5km',
        ])->first())->not->toBeNull();
});

it('reaches a target that lands inside the trailing sub-km leftover', function (): void {
    // 21 full kms only cover 21 000 m, so the half-marathon's last 97.5 m sits
    // in the partial row. Without it the PR would silently never be detected.
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 21_200,
        'stream_summary' => [
            'per_km' => evenPerKm(21, 360),
            'partial_split' => ['distance_m' => 200, 'pace' => '6:00'],
        ],
    ]);

    $broken = $this->records->detectAndStore($activity, $detail);

    expect($broken)->toContain('half_marathon')
        ->and(PersonalRecord::query()->where('user_id', $user->id)->where('category', 'half_marathon')->value('value_sec'))
        ->toEqualWithDelta(7_595.1, 0.1);
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
        'stream_summary' => ['per_km' => evenPerKm(5, 360)],
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
        'stream_summary' => ['per_km' => evenPerKm(5, 380)],
    ]);

    $broken = $this->records->detectAndStore($activity, $detail);

    expect($broken)->toContain('5km')
        ->and(PersonalRecord::query()->where('user_id', $userB->id)->where('category', '5km')->value('value_sec'))
        ->toBe(1500.0);
});

it('stages no analysis row of its own, leaving the fan-out to DispatchPostRunAnalysis', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1500.0,
    ]);
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'stream_summary' => ['per_km' => evenPerKm(5, 280)],
    ]);

    expect($this->records->detectAndStore($activity, $detail))->toContain('5km')
        ->and(Analysis::query()->count())->toBe(0);
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
        'stream_summary' => ['per_km' => evenPerKm(5, 300)],
        'start_date_local' => now(),
    ]);

    $this->records->rebuildForUser($user);

    $km = PersonalRecord::query()->where('user_id', $user->id)->where('category', '1km')->first();
    expect($km)->not->toBeNull()
        ->and($km->value_sec)->toEqualWithDelta(300.0, 0.01)
        ->and($km->activity_id)->toBe($activity->id);
});
