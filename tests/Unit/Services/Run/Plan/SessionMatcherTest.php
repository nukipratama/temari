<?php

declare(strict_types=1);

use App\Enums\IntentVerdict;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\SessionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function logRun(User $user, string $date, float $km): void
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->create([
        'activity_id' => $activity->id,
        'start_date_local' => Carbon::parse($date.' 06:00:00'),
        'distance' => $km * 1000,
    ]);
}

function logUnanalyzedRun(User $user, string $date, float $km): void
{
    $activity = Activity::factory()->for($user)->stub()->create();
    ActivityDetail::factory()->create([
        'activity_id' => $activity->id,
        'start_date_local' => Carbon::parse($date.' 06:00:00'),
        'distance' => $km * 1000,
    ]);
}

it('leaves a date that has not happened yet as planned', function (): void {
    expect(SessionMatcher::scoreFor(10.0, 0.0, false, false))->toBe([
        'status' => PlannedSessionStatus::Planned,
        'score' => null,
        'ran_anyway' => false,
    ]);
});

it('credits a day still in progress once the run already clears the bar', function (): void {
    expect(SessionMatcher::scoreFor(10.0, 10.0, false, false))->toBe([
        'status' => PlannedSessionStatus::Done,
        'score' => 100,
        'ran_anyway' => false,
    ])
        ->and(SessionMatcher::scoreFor(10.0, 5.0, false, false)['status'])->toBe(PlannedSessionStatus::Partial)
        ->and(SessionMatcher::scoreFor(10.0, 20.0, false, false)['status'])->toBe(PlannedSessionStatus::Overreached);
});

it('floors a day still in progress back to planned when the run falls short', function (): void {
    // Missed is the one verdict the hours left in the day can still overturn.
    expect(SessionMatcher::scoreFor(10.0, 1.0, false, false))->toBe([
        'status' => PlannedSessionStatus::Planned,
        'score' => null,
        'ran_anyway' => false,
    ]);
});

it('leaves a rest day still in progress as planned rather than crediting the day off', function (): void {
    expect(SessionMatcher::scoreFor(0.0, 0.0, false, false)['status'])->toBe(PlannedSessionStatus::Planned)
        ->and(SessionMatcher::scoreFor(0.0, 5.0, false, false)['ran_anyway'])->toBeFalse();
});

it('skip wins over everything, even if the athlete happened to run', function (): void {
    expect(SessionMatcher::scoreFor(10.0, 10.0, true, true))->toBe([
        'status' => PlannedSessionStatus::Skip,
        'score' => null,
        'ran_anyway' => false,
    ])
        ->and(SessionMatcher::scoreFor(0.0, 5.0, true, true)['status'])->toBe(PlannedSessionStatus::Skip);
});

it('counts a rest day as done, since it asked for nothing', function (): void {
    $result = SessionMatcher::scoreFor(0.0, 0.0, true, false);

    expect($result['status'])->toBe(PlannedSessionStatus::Done)
        ->and($result['score'])->toBeNull()
        ->and($result['ran_anyway'])->toBeFalse();
});

it('flags ran_anyway on a rest day with any activity logged, without changing the Done status', function (): void {
    $result = SessionMatcher::scoreFor(0.0, 3.5, true, false);

    expect($result['status'])->toBe(PlannedSessionStatus::Done)
        ->and($result['ran_anyway'])->toBeTrue();
});

it('grades a past session on a continuous km-ratio score, banded into a status', function (): void {
    expect(SessionMatcher::scoreFor(10.0, 10.0, true, false))->toBe(['status' => PlannedSessionStatus::Done, 'score' => 100, 'ran_anyway' => false])
        ->and(SessionMatcher::scoreFor(10.0, 10.0 * SessionMatcher::DONE_FRACTION, true, false)['status'])->toBe(PlannedSessionStatus::Done)
        ->and(SessionMatcher::scoreFor(10.0, 5.0, true, false))->toBe(['status' => PlannedSessionStatus::Partial, 'score' => 50, 'ran_anyway' => false])
        ->and(SessionMatcher::scoreFor(10.0, 10.0 * SessionMatcher::PARTIAL_FRACTION, true, false)['status'])->toBe(PlannedSessionStatus::Partial)
        ->and(SessionMatcher::scoreFor(10.0, 1.0, true, false))->toBe(['status' => PlannedSessionStatus::Missed, 'score' => 10, 'ran_anyway' => false])
        ->and(SessionMatcher::scoreFor(10.0, 0.0, true, false)['status'])->toBe(PlannedSessionStatus::Missed);
});

it('flags Overreached at and above the overreached fraction', function (): void {
    expect(SessionMatcher::scoreFor(10.0, 10.0 * SessionMatcher::OVERREACHED_FRACTION, true, false))
        ->toBe(['status' => PlannedSessionStatus::Overreached, 'score' => 130, 'ran_anyway' => false])
        ->and(SessionMatcher::scoreFor(10.0, 12.9, true, false)['status'])->toBe(PlannedSessionStatus::Done)
        ->and(SessionMatcher::scoreFor(10.0, 20.0, true, false))->toBe(['status' => PlannedSessionStatus::Overreached, 'score' => 200, 'ran_anyway' => false]);
});

it('returns no statuses for an empty plan', function (): void {
    $matcher = app(SessionMatcher::class);

    expect($matcher->statuses(User::factory()->create(), [], [], Carbon::parse('2026-08-10')))->toBe([]);
});

it('matches each logged run to the day the plan asked for it', function (): void {
    $user = User::factory()->create();
    logRun($user, '2026-08-03', 10.0);
    logRun($user, '2026-08-04', 4.0);

    $statuses = app(SessionMatcher::class)->statuses($user, [
        '2026-08-03' => 10.0,
        '2026-08-04' => 10.0,
        '2026-08-05' => 10.0,
        '2026-08-10' => 10.0,
    ], [], Carbon::parse('2026-08-10'));

    expect($statuses)->toBe([
        '2026-08-03' => PlannedSessionStatus::Done,
        '2026-08-04' => PlannedSessionStatus::Partial,
        '2026-08-05' => PlannedSessionStatus::Missed,
        '2026-08-10' => PlannedSessionStatus::Planned,
    ]);
});

it('sums two runs on the same day before grading it', function (): void {
    $user = User::factory()->create();
    logRun($user, '2026-08-03', 6.0);
    logRun($user, '2026-08-03', 5.0);

    $statuses = app(SessionMatcher::class)->statuses($user, ['2026-08-03' => 10.0], [], Carbon::parse('2026-08-10'));

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Done);
});

it('ignores another athlete\'s runs', function (): void {
    $user = User::factory()->create();
    logRun(User::factory()->create(), '2026-08-03', 10.0);

    $statuses = app(SessionMatcher::class)->statuses($user, ['2026-08-03' => 10.0], [], Carbon::parse('2026-08-10'));

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Missed);
});

it('ignores a not-yet-analyzed activity when scoring a day', function (): void {
    $user = User::factory()->create();
    logUnanalyzedRun($user, '2026-08-03', 10.0);

    $statuses = app(SessionMatcher::class)->statuses($user, ['2026-08-03' => 10.0], [], Carbon::parse('2026-08-10'));

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Missed);
});

it('activityByDate ignores a not-yet-analyzed activity', function (): void {
    $user = User::factory()->create();
    logRun($user, '2026-08-03', 5.0);
    logUnanalyzedRun($user, '2026-08-04', 10.0);

    $byDate = app(SessionMatcher::class)->activityByDate($user, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-10'));

    expect($byDate)->toHaveKey('2026-08-03')
        ->and($byDate)->not->toHaveKey('2026-08-04');
});

it('resolves a skipped day to Skip regardless of what was logged', function (): void {
    $user = User::factory()->create();
    logRun($user, '2026-08-03', 10.0);

    $statuses = app(SessionMatcher::class)->statuses(
        $user,
        ['2026-08-03' => 10.0],
        ['2026-08-03' => true],
        Carbon::parse('2026-08-10'),
    );

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Skip);
});

it('scoreRange returns the full verdict per day, not just the status', function (): void {
    $user = User::factory()->create();
    logRun($user, '2026-08-03', 10.0);

    $results = app(SessionMatcher::class)->scoreRange(
        $user,
        ['2026-08-03' => 10.0, '2026-08-04' => 0.0],
        [],
        Carbon::parse('2026-08-10'),
    );

    expect($results['2026-08-03'])->toBe(['status' => PlannedSessionStatus::Done, 'score' => 100, 'ran_anyway' => false])
        ->and($results['2026-08-04'])->toBe(['status' => PlannedSessionStatus::Done, 'score' => null, 'ran_anyway' => false]);
});

function prescribe(User $user, string $date, SessionType $type): void
{
    PlannedSession::factory()->for($user)->create([
        'date' => $date,
        'phase' => PlanPhase::Build,
        'session_type' => $type,
    ]);
}

it('withholds done from a long day nothing single ran most of', function (): void {
    $user = User::factory()->create();
    prescribe($user, '2026-08-03', SessionType::Long);
    logRun($user, '2026-08-03', 6.0);
    logRun($user, '2026-08-03', 6.0);

    $statuses = app(SessionMatcher::class)->statuses($user, ['2026-08-03' => 12.0], [], Carbon::parse('2026-08-10'));

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Partial);
});

it('still sums the day everywhere a long run was not what was asked for', function (): void {
    $user = User::factory()->create();
    prescribe($user, '2026-08-03', SessionType::Easy);
    logRun($user, '2026-08-03', 6.0);
    logRun($user, '2026-08-03', 6.0);

    $statuses = app(SessionMatcher::class)->statuses($user, ['2026-08-03' => 10.0], [], Carbon::parse('2026-08-10'));

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Done);
});

/**
 * A quality day is one effort, so it is credited from its best single run;
 * everything else, a long run included, counts the volume the day actually
 * accumulated. Narration reads this same figure, so the rule lives here
 * rather than at each call site, where the two would drift.
 */
it('credits a quality day from its best run and every other day from the total', function (SessionType $type, float $expected): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => $type,
    ]);

    foreach ([18_000.0, 5_000.0] as $metres) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::today()->setTime(7, 0),
            'distance' => $metres,
        ]);
    }

    expect(app(SessionMatcher::class)->creditedKmFor($session))->toBe($expected);
})->with([
    [SessionType::Tempo, 18.0],
    [SessionType::Interval, 18.0],
    [SessionType::Long, 23.0],
    [SessionType::Easy, 23.0],
]);

it('credits nothing on a day with no runs at all', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Easy,
    ]);

    expect(app(SessionMatcher::class)->creditedKmFor($session))->toBeNull();
});

/**
 * A quality session is one effort. Two easy 5 km outings on a tempo day are
 * not a 10 km tempo, so the day is credited from its best single run rather
 * than from what the two add up to.
 */
it('credits a quality day from its best single run rather than the day adding up', function (SessionType $type): void {
    $user = User::factory()->create();
    prescribe($user, '2026-08-03', $type);
    logRun($user, '2026-08-03', 5.0);
    logRun($user, '2026-08-03', 5.0);

    $statuses = app(SessionMatcher::class)->statuses($user, ['2026-08-03' => 10.0], [], Carbon::parse('2026-08-10'));

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Partial);
})->with([[SessionType::Tempo], [SessionType::Interval]]);

/**
 * The volume genuinely accumulated, so the day counts it; the training effect
 * of a long run is continuity, so the day only reads `done` when one run
 * carried most of it.
 */
it('sums a long day but withholds done unless one run carried 70% of the ask', function (): void {
    $user = User::factory()->create();
    prescribe($user, '2026-08-03', SessionType::Long);
    logRun($user, '2026-08-03', 6.0);
    logRun($user, '2026-08-03', 6.0);

    $split = app(SessionMatcher::class)->scoreRange($user, ['2026-08-03' => 12.0], [], Carbon::parse('2026-08-10'));

    // The volume is all there, so the score says so; the status does not.
    expect($split['2026-08-03']['status'])->toBe(PlannedSessionStatus::Partial)
        ->and($split['2026-08-03']['score'])->toBe(100);
});

it('reads a long day as done once one run carried 70% of the ask', function (): void {
    $user = User::factory()->create();
    prescribe($user, '2026-08-03', SessionType::Long);
    logRun($user, '2026-08-03', 9.0);
    logRun($user, '2026-08-03', 3.0);

    $statuses = app(SessionMatcher::class)->statuses($user, ['2026-08-03' => 12.0], [], Carbon::parse('2026-08-10'));

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Done);
});

/**
 * The readiness clamp writes `rest_clamped_at`, and an excused day is never
 * graded whatever crediting rule the session type would otherwise apply.
 */
it('leaves a rest-clamped day excused rather than missed under every crediting rule', function (SessionType $type): void {
    $user = User::factory()->create();
    prescribe($user, '2026-08-03', $type);

    $statuses = app(SessionMatcher::class)->statuses(
        $user,
        ['2026-08-03' => 12.0],
        ['2026-08-03' => true],
        Carbon::parse('2026-08-10'),
    );

    expect($statuses['2026-08-03'])->toBe(PlannedSessionStatus::Skip);
})->with([[SessionType::Long], [SessionType::Tempo], [SessionType::Easy]]);

it('records a run on an excused day without letting it score against the athlete', function (): void {
    $user = User::factory()->create();
    prescribe($user, '2026-08-03', SessionType::Rest);
    logRun($user, '2026-08-03', 4.0);

    $verdicts = app(SessionMatcher::class)->scoreRange($user, ['2026-08-03' => 0.0], [], Carbon::parse('2026-08-10'));

    expect($verdicts['2026-08-03'])->toBe([
        'status' => PlannedSessionStatus::Done,
        'score' => null,
        'ran_anyway' => true,
    ]);
});

/**
 * The duration beside a run on the plan row is elapsed time, the same basis
 * the run's own pace and every other surface reads.
 */
it('reports each run duration on elapsed time', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->create([
        'activity_id' => $activity->id,
        'start_date_local' => Carbon::parse('2026-08-03 06:00:00'),
        'distance' => 10_000.0,
        'moving_time' => 3_000,
        'elapsed_time' => 3_600,
    ]);

    $byDate = app(SessionMatcher::class)->activityByDate($user, Carbon::parse('2026-08-03'), Carbon::parse('2026-08-03'));

    expect($byDate['2026-08-03']['runs'][0]['seconds'])->toBe(3_600);
});

/**
 * The ran pace beside a graded day is moving time over distance, not elapsed
 * time — a run's stoppage time never lands on a paused watch. Carried
 * alongside `seconds` rather than in place of it, since the two read
 * different things.
 */
it('carries each run\'s moving time beside its elapsed time', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->create([
        'activity_id' => $activity->id,
        'start_date_local' => Carbon::parse('2026-08-03 06:00:00'),
        'distance' => 10_000.0,
        'moving_time' => 3_000,
        'elapsed_time' => 3_600,
    ]);

    $byDate = app(SessionMatcher::class)->activityByDate($user, Carbon::parse('2026-08-03'), Carbon::parse('2026-08-03'));

    expect($byDate['2026-08-03']['runs'][0]['moving_time'])->toBe(3_000);
});

it('reads a done day whose intent was missed as partial, its score capped under the done band', function (): void {
    $verdict = SessionMatcher::withIntent(SessionMatcher::scoreFor(10.0, 10.0, true, false), IntentVerdict::Missed);

    expect($verdict)->toBe(['status' => PlannedSessionStatus::Partial, 'score' => 84, 'ran_anyway' => false]);
});

it('leaves a partial or well-past day where distance put it when the intent was missed', function (): void {
    expect(SessionMatcher::withIntent(SessionMatcher::scoreFor(10.0, 6.0, true, false), IntentVerdict::Missed))
        ->toBe(['status' => PlannedSessionStatus::Partial, 'score' => 60, 'ran_anyway' => false])
        ->and(SessionMatcher::withIntent(SessionMatcher::scoreFor(10.0, 14.0, true, false), IntentVerdict::Missed))
        ->toBe(['status' => PlannedSessionStatus::Overreached, 'score' => 140, 'ran_anyway' => false]);
});

it('reads any credited day run too hard as overreached, keeping its distance score', function (): void {
    expect(SessionMatcher::withIntent(SessionMatcher::scoreFor(10.0, 10.0, true, false), IntentVerdict::TooHard))
        ->toBe(['status' => PlannedSessionStatus::Overreached, 'score' => 100, 'ran_anyway' => false])
        ->and(SessionMatcher::withIntent(SessionMatcher::scoreFor(10.0, 5.0, true, false), IntentVerdict::TooHard)['status'])
        ->toBe(PlannedSessionStatus::Overreached);
});

it('grades on distance alone when the intent was hit or cannot be told, or the day was never credited', function (): void {
    $done = SessionMatcher::scoreFor(10.0, 10.0, true, false);
    $missed = SessionMatcher::scoreFor(10.0, 1.0, true, false);
    $excused = SessionMatcher::scoreFor(10.0, 10.0, true, true);

    expect(SessionMatcher::withIntent($done, IntentVerdict::Hit))->toBe($done)
        ->and(SessionMatcher::withIntent($done, IntentVerdict::Unknown))->toBe($done)
        ->and(SessionMatcher::withIntent($missed, IntentVerdict::TooHard))->toBe($missed)
        ->and(SessionMatcher::withIntent($excused, IntentVerdict::Missed))->toBe($excused);
});
