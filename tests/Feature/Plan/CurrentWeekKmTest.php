<?php

declare(strict_types=1);

use App\Enums\PlannedSessionStatus;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\Agent\Tools\PlanContextTool;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\CurrentWeekKm;
use App\Services\Run\Plan\CurrentWeekPlanBuilder;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

const WEEK_KM_WEDNESDAY = '2026-10-07';

const WEEK_KM_TODAY = '2026-10-08';

const WEEK_KM_SATURDAY = '2026-10-10';

const WEEK_KM_SUNDAY = '2026-10-11';

beforeEach(fn () => Carbon::setTestNow(WEEK_KM_TODAY.' 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

/**
 * The #1994 week: a past pinned make-up easy day asked at 4.4 km, today and
 * Saturday easy at 2.7, and a Sunday long run at 6.8. The week's multiplier
 * puts the long run at 6.8 km, so the primary easy day is 0.65 of it and the
 * other easy days 0.40.
 */
function issueWeek(?float $wednesdayRunKm): User
{
    $user = User::factory()->create();
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
        'start_date_local' => '2026-09-24 07:00:00',
        'distance' => 10_000,
    ]);
    if ($wednesdayRunKm !== null) {
        ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
            'start_date_local' => WEEK_KM_WEDNESDAY.' 07:00:00',
            'distance' => $wednesdayRunKm * 1000,
        ]);
    }
    $multiplier = 6.8 / app(TrainingBaseline::class)->forUser($user, Carbon::today())['long_run_km'];

    foreach ([
        '2026-10-05' => [SessionType::Rest, []],
        '2026-10-06' => [SessionType::Rest, []],
        WEEK_KM_WEDNESDAY => [SessionType::Easy, ['pinned' => true, 'prescribed_km' => 4.4, 'status' => $wednesdayRunKm === null ? PlannedSessionStatus::Missed : PlannedSessionStatus::Done]],
        WEEK_KM_TODAY => [SessionType::Easy, []],
        '2026-10-09' => [SessionType::Rest, []],
        WEEK_KM_SATURDAY => [SessionType::Easy, []],
        WEEK_KM_SUNDAY => [SessionType::Long, []],
    ] as $date => [$type, $attributes]) {
        PlannedSession::factory()->for($user)->create([
            'date' => $date,
            'phase' => PlanPhase::Deload,
            'session_type' => $type,
            'volume_multiplier' => $multiplier,
            ...$attributes,
        ]);
    }

    return $user;
}

/** @return array<string, array<string, mixed>> */
function narratedWeek(User $user): array
{
    $tool = new PlanContextTool($user, Carbon::today(), Carbon::parse(WEEK_KM_SUNDAY), app(TrainingBaseline::class), app(VdotEstimator::class), app(TrainingPaceCalculator::class));

    return collect($tool->handle([])['days'])->keyBy('date')->all();
}

it('totals the issue week at the 16.6 km the plan asks for, and the page, header and narrator agree on Saturday', function (): void {
    $user = issueWeek(5.0);

    $week = app(CurrentWeekKm::class)->forUser($user, Carbon::today());
    $home = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());
    $days = collect($home['days'])->keyBy('date');
    $targetKm = round(array_sum(array_column($home['days'], 'asked_km')), 1);

    expect([$days[WEEK_KM_WEDNESDAY]['asked_km'], $days[WEEK_KM_TODAY]['asked_km'], $days[WEEK_KM_SATURDAY]['asked_km'], $days[WEEK_KM_SUNDAY]['asked_km']])->toBe([4.4, 2.7, 2.7, 6.8])
        ->and($targetKm)->toBe(16.6)
        ->and($days[WEEK_KM_WEDNESDAY]['credited_km'])->toBe(5.0)
        ->and($week['scale_by_date'][WEEK_KM_SATURDAY])->toEqualWithDelta((16.6 - 5.0 - 2.7 - 6.8) / 2.7, 0.0001)
        ->and($days[WEEK_KM_SATURDAY]['distance_km'])->toBe(2.1)
        ->and($week['km_by_date'][WEEK_KM_SATURDAY])->toBe(2.1)
        ->and(narratedWeek($user)[WEEK_KM_SATURDAY]['distance_km'])->toBe(2.1)
        ->and(narratedWeek($user)[WEEK_KM_TODAY]['distance_km'])->toBe($days[WEEK_KM_TODAY]['distance_km'])
        ->and($home['planned_km_this_week'])->toBe(round(5.0 + 2.7 + 2.1 + 6.8, 1))
        ->and($home['planned_km_this_week'])->toBe($targetKm)
        ->and($home['planned_km_eased_from'])->toBeNull();
});

it('drops the total by a missed day\'s km, since the cap never makes them up', function (): void {
    $user = issueWeek(null);

    $home = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());
    $days = collect($home['days'])->keyBy('date');

    expect($days[WEEK_KM_SATURDAY]['distance_km'])->toBe(2.7)
        ->and(narratedWeek($user)[WEEK_KM_SATURDAY]['distance_km'])->toBe(2.7)
        ->and($home['planned_km_this_week'])->toBe(round(16.6 - 4.4, 1));
});

it('ends above the plan\'s target when the trim is floor-bound and cannot absorb an overrun', function (): void {
    $user = issueWeek(6.0);

    $week = app(CurrentWeekKm::class)->forUser($user, Carbon::today());
    $home = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());
    $days = collect($home['days'])->keyBy('date');

    expect($week['scale_by_date'][WEEK_KM_SATURDAY])->toBe(0.7)
        ->and($days[WEEK_KM_SATURDAY]['distance_km'])->toBe(round(2.7 * 0.7, 1))
        ->and(narratedWeek($user)[WEEK_KM_SATURDAY]['distance_km'])->toBe(round(2.7 * 0.7, 1))
        ->and($home['planned_km_this_week'])->toBe(round(6.0 + 2.7 + round(2.7 * 0.7, 1) + 6.8, 1))
        ->and($home['planned_km_this_week'])->toBeGreaterThan(16.6);
});

it('gives the narrator today\'s advised session, its type and km together', function (): void {
    $user = issueWeek(5.0);
    PlannedSession::query()->where('user_id', $user->id)->whereDate('date', WEEK_KM_TODAY)->update(['session_type' => SessionType::Tempo]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => WEEK_KM_SUNDAY,
        'form_status' => 'overreaching',
        'monotony' => 1.0,
    ]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
        'start_date_local' => WEEK_KM_WEDNESDAY.' 12:00:00',
        'elapsed_time' => 3600,
        'distance' => 10_000,
        'has_heartrate' => true,
        'trimp_edwards' => 130.0,
        'stream_summary' => ['time_in_zone_min' => ['Z2' => 20, 'Z4' => 12]],
    ]);

    $today = collect(app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today())['days'])->firstWhere('date', WEEK_KM_TODAY);
    $narrated = narratedWeek($user)[WEEK_KM_TODAY];

    expect($today['eased_from']['session_type'])->toBe('tempo')
        ->and($today['session_type'])->toBe('easy')
        ->and([$narrated['session_type'], $narrated['distance_km']])->toBe([$today['session_type'], $today['distance_km']]);
});

it('gives the narrator a later easy-prescribed interval as the easy run the page shows', function (): void {
    $user = issueWeek(5.0);
    PlannedSession::query()->where('user_id', $user->id)->whereDate('date', '2026-10-09')->update([
        'session_type' => SessionType::Interval,
        'prescribed_hard_minutes' => 0,
    ]);

    $friday = collect(app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today())['days'])->firstWhere('date', '2026-10-09');
    $narrated = narratedWeek($user)['2026-10-09'];

    expect($friday['session_type'])->toBe('easy')
        ->and([$narrated['session_type'], $narrated['distance_km']])->toBe([$friday['session_type'], $friday['distance_km']]);
});
