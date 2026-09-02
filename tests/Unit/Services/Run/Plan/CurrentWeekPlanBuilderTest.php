<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\CurrentWeekPlanBuilder;
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

it('builds sessions_per_week, phase, and one day payload per planned session', function (): void {
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
    $mondayTargetKm = SegmentGenerator::coreKmFor(
        SessionType::Easy,
        true,
        app(TrainingBaseline::class)->forUser($user, Carbon::today())['long_run_km'],
        1.0,
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

it('applies the multi-week Build ramp, not an isolated week-1 multiplier', function (): void {
    Carbon::setTestNow('2026-08-24'); // a Monday, so "current week" starts exactly here
    $user = User::factory()->create();
    $currentWeekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);

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
        SegmentGenerator::coreKmFor(SessionType::Easy, true, $longRunKm, $rampedMultiplier)
        + SegmentGenerator::coreKmFor(SessionType::Easy, false, $longRunKm, $rampedMultiplier) * 6,
        1,
    );
    $unrampedTotalKm = round(
        SegmentGenerator::coreKmFor(SessionType::Easy, true, $longRunKm, 1.0)
        + SegmentGenerator::coreKmFor(SessionType::Easy, false, $longRunKm, 1.0) * 6,
        1,
    );

    expect($result['planned_km_this_week'])->toBe($rampedTotalKm)
        ->and($rampedTotalKm)->toBeGreaterThan($unrampedTotalKm); // strictly more than the un-ramped (k=0) total

    Carbon::setTestNow();
});
