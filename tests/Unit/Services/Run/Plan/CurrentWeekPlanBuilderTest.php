<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\CurrentWeekPlanBuilder;
use App\Services\Run\Plan\PlanRenderer;
use App\Services\Run\Plan\SegmentGenerator;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// Every day Easy — the earliest date each week resolves as the week's
// $isPrimaryEasy day (see SegmentGenerator::coreKmFor()), matching what
// WeekPlanBuilder itself would produce for a Deload/short week.
function seedWeekOfSessions(User $user, Carbon $weekStart, PlanPhase $phase = PlanPhase::Base): void
{
    for ($i = 0; $i < 7; $i++) {
        PlannedSession::factory()->for($user)->create([
            'date' => $weekStart->copy()->addDays($i),
            'phase' => $phase,
            'session_type' => SessionType::Easy,
        ]);
    }
}

it('returns null when the current week has no planned sessions', function (): void {
    Carbon::setTestNow('2026-08-12'); // a Wednesday
    $user = User::factory()->create();

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());

    expect($result)->toBeNull();
    Carbon::setTestNow();
});

it('builds sessions_this_week, phase, and one day payload per planned session', function (): void {
    Carbon::setTestNow('2026-08-12'); // a Wednesday
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
    seedWeekOfSessions($user, $weekStart);

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());

    expect($result)->not->toBeNull()
        ->and($result['phase'])->toBe('base')
        ->and($result['days'])->toHaveCount(7)
        ->and($result['days'][0]['date'])->toBe($weekStart->toDateString());

    Carbon::setTestNow();
});

it('never prices an old race day at a newer race goal\'s time', function (): void {
    Carbon::setTestNow('2026-08-12'); // a Wednesday
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
    seedWeekOfSessions($user, $weekStart);
    // A 10K race day left in this week by a goal that has since been retired,
    // and a fresh marathon goal a month out. The row keeps its own distance;
    // it must not borrow the new goal's four-hour finish, which over 10 km
    // would read as 24:00/km.
    PlannedSession::query()->where('user_id', $user->id)->where('date', $weekStart->copy()->addDay())->update([
        'session_type' => SessionType::Race,
        'race_distance_m' => 10_000,
    ]);
    RaceGoal::factory()->for($user)->create([
        'race_date' => '2026-09-20',
        'distance_m' => 42_195,
        'goal_time_sec' => 14_400,
    ]);

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());

    $raceDay = collect($result['days'])->firstWhere('date', $weekStart->copy()->addDay()->toDateString());
    expect($raceDay['session_type'])->toBe('race')
        ->and($raceDay['segments'][0]['pace_sec_per_km'])->toBeNull();

    Carbon::setTestNow();
});

it('scopes to the given user only', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    $other = User::factory()->create();
    seedWeekOfSessions($other, Carbon::today()->startOfWeek(Carbon::MONDAY));

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());

    expect($result)->toBeNull();
    Carbon::setTestNow();
});

it('credits a past day whose completed distance met the prescribed km', function (): void {
    Carbon::setTestNow('2026-08-12'); // a Wednesday
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
    seedWeekOfSessions($user, $weekStart);

    // A stable anchor run, well outside this week, pins long_run_km so
    // Monday's own logged distance below can't retroactively change its own
    // target (TrainingBaseline reads the longest run in the trailing 28
    // days — logging Monday's own prescribed km would otherwise become that
    // longest run, shrinking the target it's being judged against).
    $anchorActivity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($anchorActivity)->create([
        'start_date_local' => $weekStart->copy()->subDays(20)->setTime(7, 0),
        'distance' => 20_000,
    ]);

    // Monday is the week's earliest Easy day, so it's $isPrimaryEasy (the
    // bigger, Medium-fraction target) — see SegmentGenerator::coreKmFor().
    // Log exactly the prescribed km: unambiguously Done (100%), nowhere near
    // either the Partial floor or the Overreached ceiling.
    $baselineData = app(TrainingBaseline::class)->forUser($user, Carbon::today());
    $mondayTargetKm = SegmentGenerator::coreKmFor(
        SessionType::Easy,
        true,
        $baselineData['long_run_km'],
        1.0,
        $baselineData['long_run_cap_km'],
    );
    $monday = $weekStart->copy();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $monday->copy()->setTime(7, 0),
        'distance' => $mondayTargetKm * 1000,
    ]);

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());

    expect($result['credited_this_week'])->toBeGreaterThanOrEqual(1)
        ->and($result['days'][0]['status'])->toBe('done');

    Carbon::setTestNow();
});

it('renders ran_anyway true for a past, unscored rest day with a logged run', function (): void {
    Carbon::setTestNow('2026-08-12'); // a Wednesday
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
    seedWeekOfSessions($user, $weekStart);

    $monday = $weekStart->copy();
    PlannedSession::query()->where('user_id', $user->id)->where('date', $monday->toDateString())->update([
        'session_type' => SessionType::Rest,
    ]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $monday->copy()->setTime(7, 0),
        'distance' => 5_000.0,
    ]);

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());
    $day = collect($result['days'])->firstWhere('date', $monday->toDateString());

    expect($day['status'])->toBe('done')
        ->and($day['ran_anyway'])->toBeTrue();

    Carbon::setTestNow();
});

it('applies the multi-week Build ramp, not an isolated week-1 multiplier', function (): void {
    Carbon::setTestNow('2026-08-24'); // a Monday, so "current week" starts exactly here
    $user = User::factory()->create();
    $currentWeekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);

    // A race goal, so the arc is not self-scaled — a self-scaled Build phase
    // holds flat at 1.0 by design and would never exercise the ramp this test
    // is about.
    RaceGoal::factory()->for($user)->create(['race_date' => '2026-11-01', 'distance_m' => 10_000]);

    // 3 trailing Build weeks + the current (4th) Build week.
    for ($w = 3; $w >= 0; $w--) {
        seedWeekOfSessions($user, $currentWeekStart->copy()->subWeeks($w), PlanPhase::Build);
    }

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());

    // The 4th consecutive Build week (0-indexed k=3): BUILD_WEEKLY_RAMP ** 3.
    // A week computed in isolation (no trailing history) would wrongly see
    // k=0 and apply no ramp at all — this is exactly the drift the shared
    // PlanRenderer::weekPhasesAndMultipliers() computation exists to prevent.
    // Only Monday (the week's earliest Easy day) gets the Medium fraction;
    // the other 6 get Short — see SegmentGenerator::coreKmFor().
    $longRunKm = app(TrainingBaseline::class)->forUser($user, Carbon::today())['long_run_km'];
    $rampedMultiplier = 1.075 ** 3;
    $rampedTotalKm = round(
        SegmentGenerator::coreKmFor(SessionType::Easy, true, $longRunKm, $rampedMultiplier, INF)
        + SegmentGenerator::coreKmFor(SessionType::Easy, false, $longRunKm, $rampedMultiplier, INF) * 6,
        1,
    );
    $unrampedTotalKm = round(
        SegmentGenerator::coreKmFor(SessionType::Easy, true, $longRunKm, 1.0, INF)
        + SegmentGenerator::coreKmFor(SessionType::Easy, false, $longRunKm, 1.0, INF) * 6,
        1,
    );

    expect($result['planned_km_this_week'])->toBe($rampedTotalKm)
        ->and($rampedTotalKm)->toBeGreaterThan($unrampedTotalKm); // strictly more than the un-ramped (k=0) total

    Carbon::setTestNow();
});

it('counts only training days, so a week off never credits itself', function (): void {
    Carbon::setTestNow('2026-08-16'); // the Sunday, so the whole week is past
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);

    // Two Easy days and five Rest days. Nothing is logged all week, so the
    // two training days score Missed and the five rest days score Done —
    // which used to make an entirely unrun week read as 5 credited.
    for ($i = 0; $i < 7; $i++) {
        PlannedSession::factory()->for($user)->create([
            'date' => $weekStart->copy()->addDays($i),
            'phase' => PlanPhase::Base,
            'session_type' => $i < 2 ? SessionType::Easy : SessionType::Rest,
        ]);
    }

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());

    expect($result['sessions_this_week'])->toBe(2)
        ->and($result['credited_this_week'])->toBe(0);

    Carbon::setTestNow();
});

it('sizes the ring by the days the week actually holds, not the weekly target', function (): void {
    Carbon::setTestNow('2026-08-16'); // Sunday
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);

    // A plan that began mid-week: WeekPlanBuilder never writes a row before
    // the day it ran, so only Saturday and Sunday exist. A denominator taken
    // from the baseline's weekly target would put the ring out of reach.
    foreach ([5, 6] as $offset) {
        PlannedSession::factory()->for($user)->create([
            'date' => $weekStart->copy()->addDays($offset),
            'phase' => PlanPhase::Base,
            'session_type' => SessionType::Easy,
        ]);
    }

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());

    expect($result['sessions_this_week'])->toBe(2);

    Carbon::setTestNow();
});

it('credits today once the run already clears the bar, without waiting for the nightly scorer', function (): void {
    Carbon::setTestNow('2026-08-12 19:00:00'); // a Wednesday evening
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
    seedWeekOfSessions($user, $weekStart);

    $anchor = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($anchor)->create([
        'start_date_local' => $weekStart->copy()->subDays(20)->setTime(7, 0),
        'distance' => 20_000,
    ]);

    // Comfortably past the whole week's prescription, so today's own row
    // clears its target whatever the baseline resolves it to.
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->copy()->setTime(17, 0),
        'distance' => 30_000,
    ]);

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());
    $today = collect($result['days'])->firstWhere('date', Carbon::today()->toDateString());

    expect($today['status'])->toBe('overreached')
        ->and($today['actual_km'])->toBe(30.0)
        ->and($today['activities'])->toHaveCount(1)
        ->and($result['credited_this_week'])->toBeGreaterThanOrEqual(1);

    Carbon::setTestNow();
});

it('reports the km it renders, so a clamped today cannot disagree with the headline', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    seedWeekOfSessions($user, Carbon::today()->startOfWeek(Carbon::MONDAY));

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());

    expect($result['planned_km_this_week'])
        ->toBe(round(array_sum(array_column($result['days'], 'distance_km')), 1));

    Carbon::setTestNow();
});

it('holds todays advisory clamp while a run inside the load window still awaits hydration, and resumes once it lands', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    seedWeekOfSessions($user, Carbon::today()->startOfWeek(Carbon::MONDAY));
    [$row] = tempoToday($user);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'form_status' => 'overreaching',
        'monotony' => 1.0,
    ]);
    $activity = Activity::factory()->summaryOnly()->for($user)->create();
    // No heart rate, so hydrating this run doesn't introduce a competing live form_status.
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->copy()->subDays(41)->setTime(7, 0),
        'has_heartrate' => false,
        'trimp_edwards' => null,
    ]);

    $held = collect(app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today())['days'])
        ->firstWhere('date', Carbon::today()->toDateString());

    $activity->update(['ingest_state' => IngestState::Detailed]);

    $resumed = collect(app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today())['days'])
        ->firstWhere('date', Carbon::today()->toDateString());

    expect($held['clamp'])->toBeNull()
        ->and($resumed['clamp'])->not->toBeNull();

    Carbon::setTestNow();
});

/** @return array{0: PlannedSession, 1: float} today's row turned into a tempo, and its stored core km */
function tempoToday(User $user): array
{
    $row = PlannedSession::query()->where('user_id', $user->id)->whereDate('date', Carbon::today())->firstOrFail();
    $row->update(['session_type' => SessionType::Tempo]);
    $baseline = app(TrainingBaseline::class)->forUser($user, Carbon::today());

    return [$row, PlanRenderer::coreKmForSession($row, $baseline['long_run_km'], $baseline['long_run_cap_km'], $baseline['self_scaled'])];
}

/** A tempo day eased to easy at 00:01, distance held: today's card still headlines tempo, easy as the step-down. */
it('steps a tempo day eased to easy down on Home today, tempo still leading', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    seedWeekOfSessions($user, Carbon::today()->startOfWeek(Carbon::MONDAY));
    [$row, $storedKm] = tempoToday($user);
    $row->update(['clamped_km' => $storedKm]);

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());
    $today = collect($result['days'])->firstWhere('date', Carbon::today()->toDateString());

    expect($today['session_type'])->toBe('tempo')
        ->and($today['distance_km'])->toBe($storedKm)
        ->and($today['eased_from'])->toBeNull()
        ->and($today['clamp']['session_type'])->toBe('easy')
        ->and($today['clamp']['distance_km'])->toBe($storedKm)
        ->and($result['planned_km_eased_from'])->toBeNull();

    Carbon::setTestNow();
});

/** Today's step-down no longer subtracts from the week's forecast total. */
it('keeps the week total at the un-eased distance while todays ease is only a step-down', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    seedWeekOfSessions($user, Carbon::today()->startOfWeek(Carbon::MONDAY));
    [$row, $storedKm] = tempoToday($user);
    $row->update(['clamped_km' => 1.0]);

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());
    $today = collect($result['days'])->firstWhere('date', Carbon::today()->toDateString());
    $total = round(array_sum(array_column($result['days'], 'distance_km')), 1);

    expect($today['distance_km'])->toBe($storedKm)
        ->and($result['planned_km_this_week'])->toBe($total)
        ->and($result['planned_km_eased_from'])->toBeNull();

    Carbon::setTestNow();
});

/** Told 1 km, ran 1 km: the week card credits it rather than reading it short against the tempo. */
it('credits an eased today run to its eased distance, so a compliant week never reads short', function (): void {
    Carbon::setTestNow('2026-08-12 19:00:00');
    $user = User::factory()->create();
    seedWeekOfSessions($user, Carbon::today()->startOfWeek(Carbon::MONDAY));
    [$row] = tempoToday($user);
    $row->update(['clamped_km' => 1.0]);
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::today()->setTime(17, 0),
        'distance' => 1000,
    ]);

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());
    $today = collect($result['days'])->firstWhere('date', Carbon::today()->toDateString());

    expect($today['status'])->toBe('done')
        ->and($today['distance_km'])->toBe(1.0);

    Carbon::setTestNow();
});

/**
 * A Saturday signup, backfill still landing: the first stored week holds Sat and
 * Sun alone, and the runs behind it sit earlier in the same calendar week. The
 * week card's own total covers those two rows and nothing else.
 */
it('totals the partial first week of a Saturday signup, not the runs behind it', function (): void {
    Carbon::setTestNow('2026-05-16 09:00:00');
    $user = User::factory()->create();

    foreach (['2026-05-16', '2026-05-17'] as $date) {
        PlannedSession::factory()->for($user)->create([
            'date' => $date,
            'phase' => PlanPhase::Base,
            'session_type' => SessionType::Easy,
        ]);
    }
    seedWeekOfSessions($user, Carbon::parse('2026-05-18'));

    foreach (['2026-05-11', '2026-05-12', '2026-05-13'] as $date) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::parse($date.' 06:00:00'),
            'distance' => 10000,
        ]);
    }

    $result = app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today());

    expect(array_column($result['days'], 'date'))->toBe(['2026-05-16', '2026-05-17'])
        ->and($result['sessions_this_week'])->toBe(2)
        ->and($result['planned_km_this_week'])->toBe(round(array_sum(array_column($result['days'], 'distance_km')), 1));

    Carbon::setTestNow();
});
