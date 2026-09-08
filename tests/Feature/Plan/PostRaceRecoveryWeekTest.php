<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** The Saturday the race is run, and the Monday the plan picks up after it. */
const RACE_DAY = '2026-10-31';

const MONDAY_AFTER = '2026-11-02';

function raceFinisher(): User
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
        'distance_m' => 10_000,
        'goal_time_sec' => 3_540,
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

it('opens the week after a race with recovery rather than a build week', function (): void {
    $user = raceFinisher();
    closeRaceAndRegenerate($user);

    expect(phaseByWeek($user)[MONDAY_AFTER])->toBe(PlanPhase::Deload);
});

it('asks for nothing harder than easy running in that week', function (): void {
    $user = raceFinisher();
    closeRaceAndRegenerate($user);

    $types = PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', [MONDAY_AFTER, Carbon::parse(MONDAY_AFTER)->addDays(6)->toDateString()])
        ->pluck('session_type')
        ->unique()
        ->values()
        ->all();

    expect($types)->not->toContain(SessionType::Tempo)
        ->and($types)->not->toContain(SessionType::Interval);
});

it('prescribes that week at the deload multiplier, then resumes the cycle at build', function (): void {
    $user = raceFinisher();
    closeRaceAndRegenerate($user);

    $recoveryWeek = PlannedSession::query()
        ->where('user_id', $user->id)
        ->where('date', MONDAY_AFTER)
        ->firstOrFail();

    expect(round((float) $recoveryWeek->volume_multiplier, 3))->toBe(0.65)
        ->and(phaseByWeek($user)[Carbon::parse(MONDAY_AFTER)->addWeek()->toDateString()])->toBe(PlanPhase::Build);
});

it('costs the cycle nothing — the recovery week is an extra week, not a borrowed one', function (): void {
    $user = raceFinisher();
    closeRaceAndRegenerate($user);

    $byWeek = phaseByWeek($user);
    $afterRecovery = array_map(
        fn (int $n): ?PlanPhase => $byWeek[Carbon::parse(MONDAY_AFTER)->addWeeks($n)->toDateString()] ?? null,
        [1, 2, 3, 4],
    );

    expect($afterRecovery)->toBe([PlanPhase::Build, PlanPhase::Build, PlanPhase::Build, PlanPhase::Deload]);
});

it('gives no recovery week to an athlete who called the race off instead of running it', function (): void {
    $user = raceFinisher();

    // Two weeks out, the athlete withdraws: the goal is retired without ever
    // having been run, so there is nothing to recover from.
    $calledOffOn = Carbon::parse(RACE_DAY)->subWeeks(2)->startOfWeek(Carbon::MONDAY);
    Carbon::setTestNow($calledOffOn->copy()->setTime(9, 0));
    RaceGoal::query()->where('user_id', $user->id)->update(['completed_at' => Carbon::now()]);

    app(Periodizer::class)->regenerate($user, $calledOffOn->copy());

    expect(phaseByWeek($user)[$calledOffOn->toDateString()])->toBe(PlanPhase::Build);
});
