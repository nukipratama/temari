<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Metrics\PersonalRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    $this->records = app(PersonalRecords::class);
});

/**
 * @return list<array{split: int, distance: int, elapsed_time: int}>
 */
function evenSplits(int $count, int $elapsedTime): array
{
    $splits = [];
    for ($km = 1; $km <= $count; $km++) {
        $splits[] = ['split' => $km, 'distance' => 1000, 'elapsed_time' => $elapsedTime];
    }

    return $splits;
}

it('interpolates time at distance from splits (no walk-past inflation)', function (): void {
    // Half-marathon hit mid-run; later walk splits must not inflate the PR.
    $splits = [];
    for ($km = 1; $km <= 21; $km++) {
        $splits[] = ['split' => $km, 'distance' => 1000.0, 'elapsed_time' => 480.0];
    }
    $splits[] = ['split' => 22, 'distance' => 1000.0, 'elapsed_time' => 900.0];
    $splits[] = ['split' => 23, 'distance' => 1000.0, 'elapsed_time' => 900.0];
    $splits[] = ['split' => 24, 'distance' => 1000.0, 'elapsed_time' => 900.0];
    $splits[] = ['split' => 25, 'distance' => 1000.0, 'elapsed_time' => 900.0];

    // 21 km × 480s + 97.5m of the slow km 22 ≈ 10167.75s.
    $secs = $this->records->timeAtDistance($splits, 21097.5);

    expect($secs)->toBeFloat()->toEqualWithDelta(10167.75, 1.0);
});

it('returns null and logs when splits do not cover the target distance', function (): void {
    Log::shouldReceive('warning')
        ->once()
        ->with('PersonalRecords: per-km splits did not reach target distance', Mockery::type('array'));

    $splits = [
        ['split' => 1, 'distance' => 1000, 'elapsed_time' => 400],
        ['split' => 2, 'distance' => 1000, 'elapsed_time' => 410],
    ];

    expect($this->records->timeAtDistance($splits, 10_000))->toBeNull();
});

it('logs the anomaly when truncated splits fall short of a target the run distance cleared', function (): void {
    // The run "covers" 5 km by total distance, but only 3 km of splits arrived
    // (truncated / dropped segments). Interpolation can't reach 5 km, so it
    // returns null and surfaces the inconsistency rather than skipping silently.
    Log::shouldReceive('warning')
        ->once()
        ->with('PersonalRecords: per-km splits did not reach target distance', Mockery::on(
            fn (array $ctx): bool => $ctx['target_meters'] === 5000.0
                && $ctx['accumulated_meters'] === 3000.0
                && $ctx['split_count'] === 3,
        ));

    $splits = [
        ['split' => 1, 'distance' => 1000, 'elapsed_time' => 400],
        ['split' => 2, 'distance' => 1000, 'elapsed_time' => 410],
        ['split' => 3, 'distance' => 1000, 'elapsed_time' => 420],
    ];

    expect($this->records->timeAtDistance($splits, 5000.0))->toBeNull();
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
