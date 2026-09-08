<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Plan\RestClampRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** The readiness state that bottoms the ceiling out at Rest. */
function bottomOutReadiness(User $user): void
{
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'form_status' => 'overreaching',
        'monotony' => 1.0,
    ]);
}

function todaysSession(User $user, string $type = 'interval', bool $pinned = false): PlannedSession
{
    return PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => $type,
        'pinned' => $pinned,
    ]);
}

it('records a today the ceiling downgrades all the way to rest', function (): void {
    $user = User::factory()->create();
    bottomOutReadiness($user);
    $session = todaysSession($user);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue()
        ->and($session->fresh()->rest_clamped_at)->not->toBeNull();
});

/**
 * A data-less athlete sits at `moderate_ok`, which an interval day exceeds, so
 * the eased distance is what they were actually set. It is recorded instead of
 * a rest excusal: the day still asks for a run.
 */
it('records the eased distance on a downgrade that still asks for a run', function (): void {
    $user = User::factory()->create();
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

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->clamped_km)->toBeNull()
        ->and($session->fresh()->rest_clamped_at)->toBeNull();
});

/** Write once: readiness moving later in the day does not re-set the target. */
it('never overwrites an eased target it already recorded', function (): void {
    $user = User::factory()->create();
    $session = todaysSession($user);
    $session->forceFill(['clamped_km' => 4.2])->save();

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->clamped_km)->toBe(4.2);
});

/** The render-time clamp exempts a pinned row, so nothing may excuse one here. */
it('never records against a pinned row', function (): void {
    $user = User::factory()->create();
    bottomOutReadiness($user);
    $session = todaysSession($user, pinned: true);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->rest_clamped_at)->toBeNull();
});

/**
 * Both the ingest listener and the daily briefing call this, and the listener
 * fires once per run — so a second call must not move the timestamp.
 */
it('writes once and keeps the original timestamp on a second call', function (): void {
    $user = User::factory()->create();
    bottomOutReadiness($user);
    $session = todaysSession($user);

    app(RestClampRecorder::class)->record($user, Carbon::today());
    $first = $session->fresh()->rest_clamped_at;

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->rest_clamped_at->equalTo($first))->toBeTrue();
});

it('does nothing for a user with no session today', function (): void {
    $user = User::factory()->create();
    bottomOutReadiness($user);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse();
});

it('scopes to the given user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    bottomOutReadiness($other);
    $session = todaysSession($other);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->rest_clamped_at)->toBeNull();
});

/**
 * A dashboard load minutes earlier (the ordinary render path) warms
 * TrainingLoad's 5-minute summary cache. The run that should trip the clamp
 * is ingested inside that window, so an uninvalidated read would grade
 * against the pre-run load and silently skip the one write this class exists
 * to make.
 */
it('recomputes fresh training load instead of a pre-ingest cache entry', function (): void {
    $user = User::factory()->create();
    $session = todaysSession($user);

    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'trimp_edwards' => 10.0,
        'start_date_local' => Carbon::yesterday(),
    ]);
    // Warms the cache with the pre-ingest (non-overreaching) reading, exactly
    // as a dashboard render would moments before the run comes in.
    app(TrainingLoad::class)->summary($user, Carbon::today());

    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'trimp_edwards' => 500.0,
        'start_date_local' => Carbon::today(),
    ]);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue()
        ->and($session->fresh()->rest_clamped_at)->not->toBeNull();
});
