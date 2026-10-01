<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\RaceOutcome;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\RaceGoalService;
use App\Enums\RaceIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** The Saturday the race is run, and the Monday the plan picks up after it. */
const RACE_DAY = '2026-10-31';

const MONDAY_AFTER = '2026-11-02';

function raceFinisher(RaceOutcome|null $outcome = RaceOutcome::Confirmed, int $distanceM = 42_195): User
{
    $user = User::factory()->create();

    foreach (range(1, 6) as $weeksAgo) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::parse(RACE_DAY)->subWeeks($weeksAgo)->toDateString(),
            'distance_km' => 26.0,
            'runs' => 4,
        ]);
    }

    TrainingPreference::query()->create([
        'user_id' => $user->id,
        'sessions_per_week' => 4,
        'run_days' => [1, 3, 5, 6],
        'long_run_day' => 6,
    ]);

    RaceGoal::factory()->for($user)->create([
        'race_date' => RACE_DAY,
        'distance_m' => $distanceM,
        'goal_time_sec' => (int) round($distanceM * 0.36),
        'outcome' => $outcome,
    ]);

    // The arc the athlete raced off, opened eight weeks out.
    Season::factory()->for($user)->create([
        'race_goal_id' => RaceGoal::query()->where('user_id', $user->id)->value('id'),
        'anchor_weekly_volume_km' => 26.0,
        'starts_at' => '2026-09-07',
        'ends_at' => RACE_DAY,
    ]);

    return $user;
}

/**
 * Runs the daily close and the Monday regeneration in the order
 * `routes/console.php` schedules them.
 */
function closeRaceAndRegenerate(User $user, string $on = MONDAY_AFTER): void
{
    Carbon::setTestNow(Carbon::parse($on)->setTime(0, 2));
    Artisan::call('plan:close-finished-races');

    Carbon::setTestNow(Carbon::parse($on)->setTime(0, 7));
    app(Periodizer::class)->regenerate($user, Carbon::parse($on));
}

/** @return array<string, PlanPhase> week_start => phase */
function phaseByWeek(User $user): array
{
    return PlannedSession::query()
        ->where('user_id', $user->id)
        ->orderBy('date')
        ->get()
        ->groupBy(fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString())
        ->map(fn ($week): PlanPhase => $week->first()->phase)
        ->all();
}

/** @return list<SessionType> */
function sessionTypesBetween(User $user, string $from, string $to): array
{
    return PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', [$from, $to])
        ->pluck('session_type')
        ->unique()
        ->values()
        ->all();
}

function multiplierOn(User $user, string $date): float
{
    return round((float) PlannedSession::query()->where('user_id', $user->id)->where('date', $date)->value('volume_multiplier'), 3);
}

function logRaceDayRun(User $user, int $distanceM): void
{
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
        'start_date_local' => RACE_DAY.' 07:00:00',
        'distance' => $distanceM,
        'elapsed_time' => (int) round($distanceM * 0.36),
    ]);
}

function dayAfterRace(int $days): string
{
    return Carbon::parse(RACE_DAY)->addDays($days)->toDateString();
}

it('gives a marathon two weeks without quality, the first at the deload multiplier', function (): void {
    $user = raceFinisher();
    closeRaceAndRegenerate($user);

    expect(phaseByWeek($user)[MONDAY_AFTER])->toBe(PlanPhase::Deload)
        ->and(multiplierOn($user, MONDAY_AFTER))->toBe(0.65)
        ->and(sessionTypesBetween($user, dayAfterRace(1), dayAfterRace(14)))->not->toContain(SessionType::Tempo)
        ->and(sessionTypesBetween($user, dayAfterRace(1), dayAfterRace(14)))->not->toContain(SessionType::Interval)
        ->and(multiplierOn($user, Carbon::parse(MONDAY_AFTER)->addWeek()->toDateString()))->toBe(1.0);
});

it('gives a half marathon one week without quality at full volume', function (): void {
    $user = raceFinisher(distanceM: 21_098);
    closeRaceAndRegenerate($user);

    expect(phaseByWeek($user)[MONDAY_AFTER])->not->toBe(PlanPhase::Deload)
        ->and(multiplierOn($user, MONDAY_AFTER))->toBe(1.0)
        ->and(sessionTypesBetween($user, dayAfterRace(1), dayAfterRace(7)))->not->toContain(SessionType::Tempo)
        ->and(sessionTypesBetween($user, dayAfterRace(1), dayAfterRace(7)))->not->toContain(SessionType::Interval);
});

it('gives a 10K three days without quality and no deload', function (): void {
    $user = raceFinisher(distanceM: 10_000);
    closeRaceAndRegenerate($user);

    expect(multiplierOn($user, MONDAY_AFTER))->toBe(1.0)
        ->and(sessionTypesBetween($user, dayAfterRace(1), dayAfterRace(3)))->not->toContain(SessionType::Tempo)
        ->and(sessionTypesBetween($user, dayAfterRace(1), dayAfterRace(3)))->not->toContain(SessionType::Interval);
});

it('recovers from an owned race-day run that matches the event before any outcome is confirmed', function (): void {
    $user = raceFinisher(RaceOutcome::Pending);
    logRaceDayRun($user, 42_300);
    closeRaceAndRegenerate($user);

    expect(phaseByWeek($user)[MONDAY_AFTER])->toBe(PlanPhase::Deload)
        ->and(multiplierOn($user, MONDAY_AFTER))->toBe(0.65);
});

it('amends the open arc once when the race is confirmed after it opened, without replaying the season', function (): void {
    $user = raceFinisher(RaceOutcome::Pending);
    closeRaceAndRegenerate($user);
    $seasonId = Season::query()->where('user_id', $user->id)->latest('starts_at')->value('id');
    expect(phaseByWeek($user)[MONDAY_AFTER])->toBe(PlanPhase::Build);

    RaceGoal::query()->where('user_id', $user->id)->update(['outcome' => RaceOutcome::Confirmed]);
    app(Periodizer::class)->regenerate($user, Carbon::parse(MONDAY_AFTER));
    app(Periodizer::class)->regenerate($user, Carbon::parse(MONDAY_AFTER));

    expect(Season::query()->where('user_id', $user->id)->latest('starts_at')->value('id'))->toBe($seasonId)
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and(phaseByWeek($user)[MONDAY_AFTER])->toBe(PlanPhase::Deload)
        ->and(multiplierOn($user, MONDAY_AFTER))->toBe(0.65)
        ->and(phaseByWeek($user)[Carbon::parse(MONDAY_AFTER)->addWeek()->toDateString()])->not->toBe(PlanPhase::Deload);
});

it('opens a race block set inside the recovery window with that recovery', function (): void {
    $user = raceFinisher();
    Carbon::setTestNow(Carbon::parse(MONDAY_AFTER)->setTime(0, 2));
    Artisan::call('plan:close-finished-races');
    Carbon::setTestNow(Carbon::parse(MONDAY_AFTER)->setTime(9, 0));
    app(RaceGoalService::class)->submit($user, ['race_date' => '2027-03-06', 'distance_m' => 21_098, 'goal_time_sec' => 7_200, 'name' => null], RaceIntent::New);
    app(Periodizer::class)->regenerate($user, Carbon::parse(MONDAY_AFTER));

    expect(multiplierOn($user, MONDAY_AFTER))->toBeLessThanOrEqual(0.65)
        ->and(sessionTypesBetween($user, dayAfterRace(1), dayAfterRace(14)))->not->toContain(SessionType::Tempo)
        ->and(sessionTypesBetween($user, dayAfterRace(1), dayAfterRace(14)))->not->toContain(SessionType::Interval);
});

it('gives no recovery to an athlete who called the race off instead of running it', function (): void {
    $user = raceFinisher();

    $calledOffOn = Carbon::parse(RACE_DAY)->subWeeks(2)->startOfWeek(Carbon::MONDAY);
    Carbon::setTestNow($calledOffOn->copy()->setTime(9, 0));
    RaceGoal::query()->where('user_id', $user->id)->update(['completed_at' => Carbon::now()]);

    app(Periodizer::class)->regenerate($user, $calledOffOn->copy());

    expect(phaseByWeek($user)[$calledOffOn->toDateString()])->toBe(PlanPhase::Build);
});

it('gives no recovery for a race with no known load', function (?RaceOutcome $outcome): void {
    $user = raceFinisher($outcome);
    closeRaceAndRegenerate($user);

    expect(phaseByWeek($user)[MONDAY_AFTER])->toBe(PlanPhase::Build)
        ->and(multiplierOn($user, MONDAY_AFTER))->toBe(1.0);
})->with([
    'pending, no matching run' => RaceOutcome::Pending,
    'did not run' => RaceOutcome::DidNotRun,
    'cancelled' => RaceOutcome::Cancelled,
    'legacy row without an outcome' => null,
]);

it('trusts a did-not-run answer over a race-day run that happens to match', function (): void {
    $user = raceFinisher(RaceOutcome::DidNotRun);
    logRaceDayRun($user, 42_300);
    closeRaceAndRegenerate($user);

    expect(phaseByWeek($user)[MONDAY_AFTER])->toBe(PlanPhase::Build);
});
