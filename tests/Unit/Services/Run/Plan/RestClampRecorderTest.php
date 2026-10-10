<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Notifications\DayClampedNotification;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\ReadinessClamp;
use App\Services\Run\Plan\RestClampRecorder;
use App\Services\Run\Plan\EffectiveSession;
use App\Services\Run\Plan\SegmentGenerator;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('persists the interval minutes that its reduced repetitions actually render', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    $session = todaysSession($user);
    $session->update(['prescribed_hard_minutes' => 14, 'prescribed_pace_band' => 'interval', 'prescribed_pace_sec_per_km' => 240]);
    app(RestClampRecorder::class)->record($user, Carbon::today());
    $session->refresh();
    $effective = EffectiveSession::of($session, $session->clamped_km);
    $segments = SegmentGenerator::forPrescription($effective->sessionType, $session->phase, $effective->coreKm, null, $effective->qualityPrescription());
    $minutes = array_sum(array_map(static fn ($segment): float => $segment->paceLabel === PaceBand::Easy ? 0.0 : ($segment->minutes ?? 0.0), $segments));

    expect($session->readiness_assessment['adjustment']['quality_dose']['hard_minutes'])->toBe(9)
        ->and($minutes)->toBe(9.0);
    Notification::assertSentTo($user, DayClampedNotification::class, static fn (DayClampedNotification $notification): bool => str_contains($notification->note, '9 hard minutes'));
});

it('records a smaller quality dose and clears it when the demanding session ages out', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    $session = todaysSession($user, 'tempo');
    $session->update(['prescribed_hard_minutes' => 20, 'prescribed_pace_band' => 'threshold', 'prescribed_pace_sec_per_km' => 270]);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue();
    $session->refresh();
    expect($session->readiness_assessment['adjustment']['quality_dose']['hard_minutes'])->toBe(15)
        ->and($session->prescribed_hard_minutes)->toBe(20)
        ->and($session->session_type)->toBe(SessionType::Tempo);

    ActivityDetail::query()->update(['start_date_local' => Carbon::today()->subDays(3)->setTime(12, 0)]);
    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue();
    $session->refresh();
    expect($session->clamped_km)->toBeNull()->and($session->readiness_assessment)->toBeNull();
});

it('keeps an unchanged quality dose as it is and tells the athlete once', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    $session = todaysSession($user, 'tempo');
    $session->update(['prescribed_hard_minutes' => 20, 'prescribed_pace_band' => 'threshold', 'prescribed_pace_sec_per_km' => 270]);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue()
        ->and(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse();
    Notification::assertSentToTimes($user, DayClampedNotification::class, 1);
});

/** Enough PR history for a VDOT estimate, so the pace-ease branch has a pace to compute. */
function givePaceHistory(User $user): void
{
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1500,
        'set_at' => Carbon::today()->subMonth()->toDateString(),
    ]);
    seedConfirmedEffort($user, 5000, 1500, Carbon::today()->subMonth());
}

function todaysSession(User $user, string $type = 'interval', bool $pinned = false): PlannedSession
{
    return PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => $type,
        'pinned' => $pinned,
    ]);
}

it('records the morning briefing clamp before the athlete runs', function (): void {
    Carbon::withTestNow('2026-05-11 00:01:00', function (): void {
        Bus::fake();
        Notification::fake();
        $user = User::factory()->seenToday()->create();
        seedDemandingRunYesterday($user);
        $session = todaysSession($user);

        $this->artisan('ai:daily-briefing')->assertSuccessful();

        expect($session->fresh()->clamped_km)->not->toBeNull();
        Notification::assertSentToTimes($user, DayClampedNotification::class, 1);
    });
});

/**
 * A data-less athlete sits at `moderate_ok`, which an interval day exceeds, so
 * the eased distance is what they were actually set. It is recorded instead of
 * a rest excusal: the day still asks for a run.
 */
it('records the eased distance on a downgrade that still asks for a run', function (): void {
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    $session = todaysSession($user);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue()
        ->and($session->fresh()->clamped_km)->toBeFloat()
        ->and($session->fresh()->clamped_km)->toBeGreaterThan(0.0)
        ->and($session->fresh()->rest_clamped_at)->toBeNull();
});

/** An easy day needs only the floor above rest, so nothing downgrades it. */
it('leaves a day whose session already fits under the ceiling alone', function (): void {
    $user = User::factory()->create();
    $session = todaysSession($user, 'easy');

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->clamped_km)->toBeNull()
        ->and($session->fresh()->rest_clamped_at)->toBeNull();
});

/**
 * The trap this guard exists for. `Readiness::assess()` caps to EasyOnly on
 * `ranToday` alone, so after ANY run the ceiling reads easy — including on a
 * day the athlete just correctly ran their tempo. Recording an eased target
 * then would tell the scorer the day only ever asked for the easy distance and
 * grade a properly-executed session as an overreach.
 */
it('records no eased target once the athlete has already run today', function (): void {
    $user = User::factory()->create();
    // `fresh` form keeps the only cap the ranToday one, so the ceiling lands on
    // easy_only for that reason alone — which is precisely the case to refuse.
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'form_status' => 'fresh',
        'monotony' => 1.0,
    ]);
    $session = todaysSession($user);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->setTime(7, 0),
        'distance' => 5000.0,
    ]);

    app(RestClampRecorder::class)->record($user, Carbon::today());

    // Asserted on the eased target alone, not on record()'s return. A logged
    // run makes the live form_status depend on the factory's randomised heart
    // rate and moving time, so the ceiling lands on rest or easy_only run to
    // run. Either way the guarantee holds: a cap the athlete's own run caused
    // is never a target they were set.
    expect($session->fresh()->clamped_km)->toBeNull();
});

it('records the eased distance at the week\'s own volume multiplier, the one the render shows', function (): void {
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Interval,
        'volume_multiplier' => 1.3,
    ]);

    app(RestClampRecorder::class)->record($user, Carbon::today());

    $baseline = app(TrainingBaseline::class)->forUser($user, Carbon::today());
    $clampAt = static fn (float $multiplier): ?float => ReadinessClamp::apply(
        SessionType::Interval,
        PlanPhase::Build,
        null,
        (float) $baseline['long_run_km'],
        $multiplier,
        (float) $baseline['long_run_cap_km'],
        null,
        ReadinessCeiling::ModerateOk,
    )['core_km'] ?? null;

    expect($clampAt(1.3))->not->toBe($clampAt(1.0))
        ->and($session->fresh()->clamped_km)->toBe($clampAt(1.3));
});

it('clears a current adjustment when readiness no longer calls for it', function (): void {
    $user = User::factory()->create();
    $session = todaysSession($user);
    $session->forceFill(['clamped_km' => 4.2])->save();

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue()
        ->and($session->fresh()->clamped_km)->toBeNull()
        ->and($session->fresh()->readiness_assessment)->toBeNull();
});

it('never records against a pinned row', function (): void {
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    $session = todaysSession($user, pinned: true);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->clamped_km)->toBeNull();
});

it('does nothing for a user with no session today', function (): void {
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse();
});

it('scopes to the given user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    seedDemandingRunYesterday($other);
    $session = todaysSession($other);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->clamped_km)->toBeNull();
});

/**
 * A dashboard load minutes earlier (the ordinary render path) warms
 * TrainingLoad's 5-minute summary cache. A yesterday run is backfilled inside
 * that window, so an uninvalidated read would grade
 * against the pre-run load and silently skip the one write this class exists
 * to make.
 */
it('recomputes fresh training load instead of a pre-ingest cache entry', function (): void {
    $user = User::factory()->create();
    $session = todaysSession($user);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'distance_km' => 100.0,
    ]);

    for ($daysAgo = 60; $daysAgo >= 2; $daysAgo -= 2) {
        ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
            'trimp_edwards' => 40.0,
            'start_date_local' => Carbon::today()->subDays($daysAgo),
        ]);
    }
    // Warms the cache with the pre-ingest reading, where the week's load is inside the personal
    // range, exactly as a dashboard render would moments before the run comes in.
    app(TrainingLoad::class)->summary($user, Carbon::today());

    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'trimp_edwards' => 500.0,
        'start_date_local' => Carbon::yesterday(),
    ]);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue()
        ->and($session->fresh()->readiness_assessment['reasons'])->toContain('weekly_load_above_personal_range');
});

it('tells the athlete when the day is eased, in the clamp note', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    todaysSession($user);

    app(RestClampRecorder::class)->record($user, Carbon::today());

    Notification::assertSentTo(
        $user,
        DayClampedNotification::class,
        fn (DayClampedNotification $n): bool => $n->note === ReadinessClamp::noteFor(SessionType::Interval, ReadinessCeiling::ModerateOk, ['demanding_session_within_24h']),
    );
});

it('says nothing on a day that already fits under the ceiling', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    todaysSession($user, 'easy');

    app(RestClampRecorder::class)->record($user, Carbon::today());

    Notification::assertNothingSent();
});

/** A moderate concern leaves a Long day at its intensity threshold. */
it('records a pace ease on a Long day at a ModerateOk ceiling', function (): void {
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    givePaceHistory($user);
    $session = todaysSession($user, 'long');

    $expectedPace = app(TrainingPaceCalculator::class)->easySlowEndFromVdotResult(
        app(VdotEstimator::class)->estimate($user, Carbon::today()),
    );

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue()
        ->and($session->fresh()->eased_pace_sec_per_km)->toBe($expectedPace)
        ->and($session->fresh()->clamped_km)->toBeNull();
});

it('records no pace ease with no VDOT estimate to size one from', function (): void {
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    $session = todaysSession($user, 'long');

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->eased_pace_sec_per_km)->toBeNull();
});

/** Mirrors the same ranToday trap the eased-distance branch guards against. */
it('records no pace ease once the athlete has already run today', function (): void {
    $user = User::factory()->create();
    givePaceHistory($user);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'form_status' => 'fresh',
        'monotony' => 1.0,
    ]);
    $session = todaysSession($user, 'easy');
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->setTime(7, 0),
        'distance' => 5000.0,
    ]);

    app(RestClampRecorder::class)->record($user, Carbon::today());

    expect($session->fresh()->eased_pace_sec_per_km)->toBeNull();
});

it('recalculates a current pace ease from current readiness and fitness', function (): void {
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    givePaceHistory($user);
    $session = todaysSession($user, 'long');
    $session->forceFill(['eased_pace_sec_per_km' => 400])->save();

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue()
        ->and($session->fresh()->eased_pace_sec_per_km)->toBe($expectedPace = app(TrainingPaceCalculator::class)->easySlowEndFromVdotResult(app(VdotEstimator::class)->estimate($user, Carbon::today())))
        ->and($session->fresh()->readiness_assessment['reasons'])->toContain('demanding_session_within_24h');
});

/** One lever per day: a day apply() already downgraded never also gets a pace ease. */
it('carries exactly one reduction — a downgraded day records no pace ease on top', function (): void {
    $user = User::factory()->create();
    givePaceHistory($user);
    seedDemandingRunYesterday($user);
    $downgraded = todaysSession($user, 'interval');

    app(RestClampRecorder::class)->record($user, Carbon::today());

    expect($downgraded->fresh()->clamped_km)->not->toBeNull()
        ->and($downgraded->fresh()->eased_pace_sec_per_km)->toBeNull();
});

it('sends no notification for a pace-only ease', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    givePaceHistory($user);
    todaysSession($user, 'long');

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue();
    Notification::assertNothingSent();
});

// No heart rate, so hydrating this run doesn't introduce a competing live form_status.
function unscoredLoadRunOn(User $user, Carbon $day): Activity
{
    $activity = Activity::factory()->summaryOnly()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $day->copy()->setTime(7, 0),
        'has_heartrate' => false,
        'trimp_edwards' => null,
    ]);

    return $activity;
}

it('holds recording while recent training history awaits hydration', function (): void {
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    $session = todaysSession($user);
    unscoredLoadRunOn($user, Carbon::today()->subDays(41));

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->clamped_km)->toBeNull();
});

it('resumes recording once the run it was held for has hydrated', function (): void {
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    $session = todaysSession($user);
    $activity = unscoredLoadRunOn($user, Carbon::today()->subDays(41));
    $activity->update(['ingest_state' => IngestState::Detailed]);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue()
        ->and($session->fresh()->clamped_km)->not->toBeNull();
});

it('ignores an unscored run outside the trailing load window', function (): void {
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    $session = todaysSession($user);
    unscoredLoadRunOn($user, Carbon::today()->subDays(42));

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue()
        ->and($session->fresh()->clamped_km)->not->toBeNull();
});
