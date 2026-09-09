<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use App\Services\Run\Trend\TrendSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');
    $this->writer = app(TrendSnapshotWriter::class);
});
afterEach(fn () => Carbon::setTestNow());

it('writes a row with vdot and pace-variability computed from real data', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 1200.0]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'stream_summary' => ['pace_variability_sec' => 6.5],
    ]);

    $this->writer->writeToday($user);

    $snap = TrendDailySnapshot::query()->where('user_id', $user->id)->sole();
    expect($snap->snapshot_date->toDateString())->toBe(Carbon::today()->toDateString())
        ->and($snap->vdot)->toBeFloat()->toBeGreaterThan(0)
        ->and($snap->pace_variability_sec)->toEqualWithDelta(6.5, 0.01);
});

it('writes null vdot when the user has no qualifying PR, and null pace-variability on a rest day', function (): void {
    $user = User::factory()->create();

    $this->writer->writeToday($user);

    $snap = TrendDailySnapshot::query()->where('user_id', $user->id)->sole();
    expect($snap->vdot)->toBeNull()
        ->and($snap->pace_variability_sec)->toBeNull();
});

it('excludes a not-yet-analyzed activity from the day\'s pace-variability average', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->setTime(6, 0),
        'stream_summary' => ['pace_variability_sec' => 4.0],
    ]);
    $unanalyzed = Activity::factory()->for($user)->stub()->create();
    ActivityDetail::factory()->for($unanalyzed)->create([
        'start_date_local' => Carbon::today()->setTime(18, 0),
        'stream_summary' => ['pace_variability_sec' => 900.0],
    ]);

    $this->writer->writeToday($user);

    $snap = TrendDailySnapshot::query()->where('user_id', $user->id)->sole();
    expect($snap->pace_variability_sec)->toEqualWithDelta(4.0, 0.01);
});

it('averages pace-variability across multiple runs the same day', function (): void {
    $user = User::factory()->create();
    foreach ([4.0, 8.0] as $variability) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::today()->setTime(6, 0),
            'stream_summary' => ['pace_variability_sec' => $variability],
        ]);
    }

    $this->writer->writeToday($user);

    $snap = TrendDailySnapshot::query()->where('user_id', $user->id)->sole();
    expect($snap->pace_variability_sec)->toEqualWithDelta(6.0, 0.01);
});

it('running the writer twice for the same day does not overwrite the existing row', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'stream_summary' => ['pace_variability_sec' => 5.0],
    ]);

    $this->writer->writeToday($user);
    $firstRowId = TrendDailySnapshot::query()->where('user_id', $user->id)->sole()->id;

    // A second run of the day (e.g. a retried cron dispatch) must not touch
    // the already-written row — this is the regression test for the
    // grow-forward guarantee (firstOrCreate, never updateOrCreate).
    $secondActivity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($secondActivity)->create([
        'start_date_local' => Carbon::today()->setTime(18, 0),
        'stream_summary' => ['pace_variability_sec' => 99.0],
    ]);
    $this->writer->writeToday($user);

    $snap = TrendDailySnapshot::query()->where('user_id', $user->id)->sole();
    expect($snap->id)->toBe($firstRowId)
        ->and($snap->pace_variability_sec)->toEqualWithDelta(5.0, 0.01);
    expect(TrendDailySnapshot::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('accepts an explicit date so a row can be written for a day other than today', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::yesterday(),
        'stream_summary' => ['pace_variability_sec' => 3.0],
    ]);

    $this->writer->writeToday($user, Carbon::yesterday());

    $snap = TrendDailySnapshot::query()->where('user_id', $user->id)->sole();
    expect($snap->snapshot_date->toDateString())->toBe(Carbon::yesterday()->toDateString());
});

it('backfills a snapshot with the VDOT the athlete had proven by that date, not by today', function (): void {
    $user = User::factory()->create();
    // Slow marathon two years back, fast kilometre last month. Today only the
    // kilometre is inside the estimator's window; on a 2024 date both are, and
    // the min-across-categories rule hands the marathon the lower VDOT.
    PersonalRecord::factory()->for($user)->create([
        'category' => 'marathon',
        'value_sec' => 20_292.0,
        'set_at' => Carbon::parse('2024-01-01'),
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => '1km',
        'value_sec' => 278.0,
        'set_at' => Carbon::parse('2026-07-01'),
    ]);

    $this->writer->writeToday($user);
    $this->writer->writeToday($user, Carbon::parse('2024-06-01'));

    $today = TrendDailySnapshot::query()->where('snapshot_date', Carbon::today()->toDateString())->sole();
    $backfilled = TrendDailySnapshot::query()->where('snapshot_date', '2024-06-01')->sole();

    expect($backfilled->vdot)->toBeLessThan($today->vdot);
});
