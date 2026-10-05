<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\FitnessAnchor;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\PersonalRecords;
use Illuminate\Support\Carbon;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
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
it('inserts a fresh distance PR when none exists', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'stream_summary' => ['per_km' => evenPerKm(5, 380)],
    ]);

    $broken = $this->records->detectAndStore($activity, $detail);

    expect($broken)->toContain('5km')
        ->and(PersonalRecord::query()->where([
            'user_id' => $user->id,
            'category' => '5km',
        ])->first())->not->toBeNull()
        ->and(FitnessAnchor::query()->where('user_id', $user->id)->value('source_activity_id'))->toBe($activity->id);
});

it('lifts the guide by at most one VDOT when a faster run overwrites the only PR after the anchor', function (): void {
    $user = User::factory()->create();
    Carbon::setTestNow('2026-06-01 09:00:00');
    $oldActivity = Activity::factory()->for($user)->create();
    $oldDetail = ActivityDetail::factory()->for($oldActivity)->create([
        'distance' => 5000,
        'start_date_local' => '2026-06-01 07:00:00',
        'stream_summary' => ['per_km' => evenPerKm(5, 360)],
    ]);
    $this->records->detectAndStore($oldActivity, $oldDetail);
    $estimator = app(VdotEstimator::class);
    $before = $estimator->estimate($user);

    Carbon::setTestNow('2026-09-01 09:00:00');
    $newActivity = Activity::factory()->for($user)->create();
    $newDetail = ActivityDetail::factory()->for($newActivity)->create([
        'distance' => 5000,
        'start_date_local' => '2026-09-01 07:00:00',
        'stream_summary' => ['per_km' => evenPerKm(5, 300)],
    ]);
    $this->records->detectAndStore($newActivity, $newDetail);
    $after = $estimator->estimate($user);
    Carbon::setTestNow();

    expect(PersonalRecord::query()->where('user_id', $user->id)->where('category', '5km')->value('activity_id'))
        ->toBe($newActivity->id)
        ->and($after['vdot'])->toBe(round($before['vdot'] + VdotEstimator::RISE_CAP_VDOT_PER_WEEK, 1));
});

it('drops the estimate at once when its source activity is deleted', function (): void {
    $user = User::factory()->create();
    $fastActivity = Activity::factory()->for($user)->create();
    $fastDetail = ActivityDetail::factory()->for($fastActivity)->create([
        'distance' => 5000,
        'start_date_local' => now()->subWeeks(4),
        'stream_summary' => ['per_km' => evenPerKm(5, 300)],
    ]);
    $this->records->detectAndStore($fastActivity, $fastDetail);
    $fastVdot = app(VdotEstimator::class)->estimate($user)['vdot'];

    $survivingActivity = Activity::factory()->for($user)->create();
    $survivingDetail = ActivityDetail::factory()->for($survivingActivity)->create([
        'distance' => 5000,
        'start_date_local' => now()->subWeeks(5),
        'stream_summary' => ['per_km' => evenPerKm(5, 420)],
    ]);
    $this->records->detectAndStore($survivingActivity, $survivingDetail);

    $fastActivity->delete();
    $this->records->rebuildForUser($user);

    $estimate = app(VdotEstimator::class)->estimate($user);
    expect($estimate['source_activity_id'])->toBe($survivingActivity->id)
        ->and($estimate['vdot'])->toBeLessThan($fastVdot);
});

it('lowers the estimate when a corrected source activity is rebuilt', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5000,
        'start_date_local' => now()->subWeeks(2),
        'stream_summary' => ['per_km' => evenPerKm(5, 300)],
    ]);
    $this->records->detectAndStore($activity, $detail);
    $fastVdot = app(VdotEstimator::class)->estimate($user)['vdot'];

    $detail->update(['stream_summary' => ['per_km' => evenPerKm(5, 420)]]);
    $this->records->rebuildForUser($user);

    $estimate = app(VdotEstimator::class)->estimate($user);
    expect($estimate['source_value_sec'])->toBe(2100.0)
        ->and($estimate['vdot'])->toBeLessThan($fastVdot);
});

it('drops a quality source after its activity is deleted', function (): void {
    $user = User::factory()->create();
    $half = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($half)->create([
        'distance' => 21_200,
        'start_date_local' => now()->subWeeks(14),
        'stream_summary' => ['per_km' => evenPerKm(21, 420), 'partial_split' => ['distance_m' => 200, 'pace' => '7:00']],
    ]);
    $fiveK = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($fiveK)->create([
        'distance' => 12_000,
        'elapsed_time' => 5_875,
        'start_date_local' => now()->subWeeks(3),
        'stream_summary' => ['per_km' => [...evenPerKm(5, 335), ...evenPerKm(7, 600)]],
    ]);
    $this->records->rebuildForUser($user);
    $estimator = app(VdotEstimator::class);
    $before = $estimator->estimate($user);

    $fiveK->delete();
    $this->records->rebuildForUser($user);
    $after = $estimator->estimate($user);

    expect($before['quality_vdot'])->toBeGreaterThan($before['vdot'])
        ->and($after['quality_vdot'])->toBe($after['vdot'])
        ->and($after['quality_source'])->toBeNull();
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

it('names the runs that set a record on their own day, judged against every earlier run', function (): void {
    $user = User::factory()->create();
    $run = function (string $date, int $secPerKm) use ($user): Activity {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => 5000,
            'stream_summary' => ['per_km' => evenPerKm(5, $secPerKm)],
            'start_date_local' => $date,
        ]);

        return $activity;
    };
    $first = $run('2026-01-01 07:00:00', 330);
    $faster = $run('2026-02-01 07:00:00', 300);
    $easy = $run('2026-09-01 07:00:00', 430);

    $setters = $this->records->recordSettingActivityIds($user);

    expect($setters)->toBe([$first->id, $faster->id])
        ->and($setters)->not->toContain($easy->id)
        ->and(PersonalRecord::query()->count())->toBe(0);
});
