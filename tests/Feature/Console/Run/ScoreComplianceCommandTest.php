<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\SegmentGenerator;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function seedPastWeekOfSessions(User $user, Carbon $weekStart, PlanPhase $phase = PlanPhase::Base): void
{
    for ($i = 0; $i < 7; $i++) {
        PlannedSession::factory()->for($user)->create([
            'date' => $weekStart->copy()->addDays($i),
            'phase' => $phase,
            'session_type' => SessionType::Easy,
        ]);
    }
}

it('scores a past week, crediting the day that met its target and marking the rest missed', function (): void {
    Carbon::setTestNow('2026-08-12'); // a Wednesday
    $user = User::factory()->create();
    $weekStart = Carbon::today()->subWeeks(2)->startOfWeek(Carbon::MONDAY);
    seedPastWeekOfSessions($user, $weekStart);

    // A stable anchor run, well outside the trailing baseline window's overlap
    // with the test week, pins long_run_km so Monday's own logged distance
    // below can't retroactively change its own target.
    $anchorActivity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($anchorActivity)->create([
        'start_date_local' => Carbon::today()->subDays(20)->setTime(7, 0),
        'distance' => 20_000,
    ]);

    $mondayTargetKm = SegmentGenerator::coreKmFor(
        SessionType::Easy,
        true,
        app(TrainingBaseline::class)->forUser($user, Carbon::today())['long_run_km'],
        1.0,
    );
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $weekStart->copy()->setTime(7, 0),
        'distance' => $mondayTargetKm * 1000,
    ]);

    $this->artisan('plan:score-compliance')
        ->expectsOutputToContain('Scored 7 planned session(s) across 1 user(s).')
        ->assertSuccessful();

    $rows = PlannedSession::query()->where('user_id', $user->id)->orderBy('date')->get();

    expect($rows[0]->status->value)->toBe('done')
        ->and($rows[0]->compliance_score)->not->toBeNull()
        ->and($rows[1]->status->value)->toBe('missed')
        ->and($rows[1]->compliance_score)->toBe(0);

    Carbon::setTestNow();
});

it('limits to a single user via --user', function (): void {
    Carbon::setTestNow('2026-08-12');
    $a = User::factory()->create();
    $b = User::factory()->create();
    $pastDate = Carbon::today()->subDays(3);

    PlannedSession::factory()->for($a)->rest()->create(['date' => $pastDate]);
    PlannedSession::factory()->for($b)->rest()->create(['date' => $pastDate]);

    $this->artisan("plan:score-compliance --user={$a->id}")
        ->expectsOutputToContain('Scored 1 planned session(s) across 1 user(s).')
        ->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $a->id)->first()->status->value)->toBe('done')
        ->and(PlannedSession::query()->where('user_id', $b->id)->first()->status->value)->toBe('planned');

    Carbon::setTestNow();
});

it('marks a skipped day as Skip regardless of logged distance', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    $pastDate = Carbon::today()->subDays(2);
    PlannedSession::factory()->for($user)->create([
        'date' => $pastDate,
        'skipped' => true,
    ]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $pastDate->copy()->setTime(7, 0),
        'distance' => 10_000,
    ]);

    $this->artisan('plan:score-compliance')->assertSuccessful();

    $row = PlannedSession::query()->where('user_id', $user->id)->first();
    expect($row->status->value)->toBe('skip')
        ->and($row->compliance_score)->toBeNull()
        ->and($row->ran_anyway)->toBeFalse();

    Carbon::setTestNow();
});

it('leaves future planned sessions untouched', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->addDay()]);

    $this->artisan('plan:score-compliance')
        ->expectsOutputToContain('Scored 0 planned session(s) across 0 user(s).')
        ->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $user->id)->first()->status->value)->toBe('planned');

    Carbon::setTestNow();
});

it('does nothing when there are no past-due Planned rows', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->scored()->create(['date' => Carbon::today()->subDays(3)]);

    $this->artisan('plan:score-compliance')
        ->expectsOutputToContain('Scored 0 planned session(s) across 0 user(s).')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('marks a past rest day Done and flags ran_anyway when logged despite being rest', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    $pastDate = Carbon::today()->subDays(2);
    PlannedSession::factory()->for($user)->rest()->create(['date' => $pastDate]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $pastDate->copy()->setTime(7, 0),
        'distance' => 5_000,
    ]);

    $this->artisan('plan:score-compliance')->assertSuccessful();

    $row = PlannedSession::query()->where('user_id', $user->id)->first();
    expect($row->status->value)->toBe('done')
        ->and($row->compliance_score)->toBeNull()
        ->and($row->ran_anyway)->toBeTrue();

    Carbon::setTestNow();
});

/**
 * The case the advisory clamp exists to fix: the card told the athlete to rest,
 * they rested, and grading them against the session it replaced scored them 0%
 * and called it `missed` for complying. The clamp cannot be recomputed here —
 * the ceiling that produced it counted that day's own runs — so
 * RestClampRecorder wrote it down at the time.
 */
it('excuses a day the readiness clamp had downgraded to a full rest', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    $pastDate = Carbon::today()->subDays(2);
    PlannedSession::factory()->for($user)->create([
        'date' => $pastDate,
        'session_type' => SessionType::Long,
        'rest_clamped_at' => $pastDate->copy()->setTime(6, 0),
    ]);

    $this->artisan('plan:score-compliance')->assertSuccessful();

    $row = PlannedSession::query()->where('user_id', $user->id)->first();
    expect($row->status->value)->toBe('skip')
        ->and($row->compliance_score)->toBeNull();

    Carbon::setTestNow();
});

it('still marks an unclamped long day missed when nothing was run', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->subDays(2),
        'session_type' => SessionType::Long,
    ]);

    $this->artisan('plan:score-compliance')->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $user->id)->first()->status->value)->toBe('missed');

    Carbon::setTestNow();
});

it('grades race day against the race distance even once the goal behind it has been retired', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    $raceDate = Carbon::today()->subDay();

    // plan:close-finished-races runs at 00:02, before this command — by the
    // time race day is graded the RaceGoal is already retired, so the row's
    // own race_distance_m is the only thing left that knows the distance.
    PlannedSession::factory()->for($user)->create([
        'date' => $raceDate,
        'phase' => PlanPhase::Taper,
        'session_type' => SessionType::Race,
        'race_distance_m' => 10_000,
    ]);

    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $raceDate->copy()->setTime(7, 0),
        'distance' => 10_000,
    ]);

    $this->artisan('plan:score-compliance')->assertSuccessful();

    $row = PlannedSession::query()->where('user_id', $user->id)->firstOrFail();

    expect($row->status)->toBe(PlannedSessionStatus::Done)
        ->and($row->compliance_score)->toBe(100)
        // The failure this replaces: a 0 km target scored the race "rest day,
        // ran anyway" instead of grading it.
        ->and($row->ran_anyway)->toBeFalse();
});
