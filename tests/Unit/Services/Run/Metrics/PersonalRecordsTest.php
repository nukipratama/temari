<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Metrics\PersonalRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('interpolates time at distance from splits (no walk-past inflation)', function (): void {
    // A 25 km run that hit 21.0975 km partway through, then cooled down walking.
    // The 22nd km is super slow (6:00 walk pace) — must NOT inflate the half PR.
    $splits = [];
    for ($km = 1; $km <= 21; $km++) {
        $splits[] = ['split' => $km, 'distance' => 1000.0, 'elapsed_time' => 480.0];
    }
    $splits[] = ['split' => 22, 'distance' => 1000.0, 'elapsed_time' => 900.0];
    $splits[] = ['split' => 23, 'distance' => 1000.0, 'elapsed_time' => 900.0];
    $splits[] = ['split' => 24, 'distance' => 1000.0, 'elapsed_time' => 900.0];
    $splits[] = ['split' => 25, 'distance' => 1000.0, 'elapsed_time' => 900.0];

    // 21 km × 480s = 10080s. + 97.5m of the 22nd km at 900s/1000m = ~87.75s. Total ~10167.75s.
    $secs = (new PersonalRecords())->timeAtDistance($splits, 21097.5);

    expect($secs)->toBeFloat()->toEqualWithDelta(10167.75, 1.0);
});

it('returns null when splits do not cover the target distance', function (): void {
    $splits = [
        ['split' => 1, 'distance' => 1000, 'elapsed_time' => 400],
        ['split' => 2, 'distance' => 1000, 'elapsed_time' => 410],
    ];

    expect((new PersonalRecords())->timeAtDistance($splits, 10_000))->toBeNull();
});

it('inserts a fresh distance PR when none exists', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $splits = array_fill(0, 6, ['distance' => 1000, 'elapsed_time' => 380]);
    foreach ($splits as $i => &$s) {
        $s['split'] = $i + 1;
    }
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 6000,
        'splits_metric' => $splits,
        'stream_summary' => null,
    ]);

    $broken = (new PersonalRecords())->detectAndStore($activity, $detail);

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
        'value_sec' => 1500.0, // existing 5km PR
    ]);

    $activity = Activity::factory()->for($user)->create();
    $splits = array_fill(0, 5, ['distance' => 1000, 'elapsed_time' => 360]); // total 1800s, slower than 1500s
    foreach ($splits as $i => &$s) {
        $s['split'] = $i + 1;
    }
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'splits_metric' => $splits,
        'stream_summary' => null,
    ]);

    $broken = (new PersonalRecords())->detectAndStore($activity, $detail);

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
            'best_5min_pace' => '5:00', // 300 sec/km — faster than 320
        ],
    ]);

    $broken = (new PersonalRecords())->detectAndStore($activity, $detail);

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
            'best_5min_pace' => 'not-a-pace',  // malformed → parsePace returns null
            'best_10min_pace' => '5:00',
        ],
    ]);

    $broken = (new PersonalRecords())->detectAndStore($activity, $detail);

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
    $splits = array_fill(0, 5, ['distance' => 1000, 'elapsed_time' => 380]);
    foreach ($splits as $i => &$s) {
        $s['split'] = $i + 1;
    }
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'splits_metric' => $splits,
        'stream_summary' => null,
    ]);

    $broken = (new PersonalRecords())->detectAndStore($activity, $detail);

    expect($broken)->toContain('5km')
        ->and(PersonalRecord::query()->where('user_id', $userB->id)->where('category', '5km')->value('value_sec'))
        ->toBe(1500.0); // unchanged
});
