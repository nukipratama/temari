<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\ExperienceLevel;
use App\Enums\PlanPhase;
use App\Enums\IngestState;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\CurrentWeekPlanBuilder;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PhaseSchedule;
use App\Services\Run\Plan\PlanPageAssembler;
use App\Services\Run\Plan\PlanRenderer;
use App\Services\Run\Plan\TrainingBaseline;
use App\Services\Run\Plan\SeasonSummaryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-21 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

/**
 * The #1000 audit's athlete, in shape: twelve weeks averaging 25.91 km with a
 * 44.7 km week in them, a 24.4 km six-week trimmed anchor, four sessions and
 * a 10K twelve weeks out. The plan used to average 22.05 km across the block.
 */
function flooredAthlete(): User
{
    $user = User::factory()->create();
    foreach ([17.4, 25.5, 23.6, 20.4, 44.7, 28.1, 27.0, 28.5, 25.9, 14.0, 25.0, 30.8] as $i => $km) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::parse('2026-09-20')->subWeeks($i)->toDateString(),
            'distance_km' => $km,
            'runs' => 4,
            'form_status' => 'optimal',
        ]);
    }
    TrainingPreference::query()->create([
        'user_id' => $user->id,
        'experience_level' => ExperienceLevel::Experienced,
        'sessions_per_week' => 4,
        'run_days' => [1, 3, 5, 6],
        'long_run_day' => 6,
    ]);
    RaceGoal::factory()->for($user)->create(['race_date' => '2026-12-13', 'distance_m' => 10_000, 'goal_time_sec' => 3_480]);

    return $user;
}

function thisWeekKm(User $user): float
{
    return app(CurrentWeekPlanBuilder::class)->forUser($user, Carbon::today())['planned_km_this_week'];
}

function thisWeekPhase(User $user): PlanPhase
{
    return PlannedSession::query()->where('user_id', $user->id)->where('date', Carbon::today()->toDateString())->value('phase');
}

it('holds the race block at the athlete\'s own recent mean when load is fine', function (): void {
    $user = flooredAthlete();

    app(Periodizer::class)->regenerate($user, Carbon::today());

    $season = Season::query()->where('user_id', $user->id)->firstOrFail();
    $block = array_filter(
        app(SeasonSummaryBuilder::class)->plannedWeeks($user, $season),
        fn (array $week): bool => $week['zone'] === PhaseSchedule::ZONE_BLOCK,
    );

    expect($season->volume_floor_km)->toBe(25.91)
        ->and(array_sum(array_column($block, 'planned_km')) / count($block))->toBeGreaterThanOrEqual(25.91)
        ->and(thisWeekKm($user))->toBeGreaterThanOrEqual(25.91)
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->value('volume_floor_km'))->toBeNull();
});

it('backs off under the floor when the athlete is overreaching, and says so on the plan', function (): void {
    $user = flooredAthlete();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-09-27',
        'form_status' => 'overreaching',
        'monotony' => 1.0,
        'distance_km' => 30.0,
        'weekly_trimp' => 300.0,
        'atl_7d' => 50.0,
        'ctl_42d' => 40.0,
    ]);

    app(Periodizer::class)->regenerate($user, Carbon::today());

    $adaptation = PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail();

    expect(thisWeekPhase($user))->toBe(PlanPhase::Deload)
        ->and(thisWeekKm($user))->toBeLessThan(25.91)
        ->and($adaptation->reason)->toBe(AdaptationReason::LowReadiness)
        ->and($adaptation->volume_floor_km)->toBe(25.91)
        ->and(app(PlanPageAssembler::class)->adaptation($user, Carbon::today())['detail'])
        ->toEndWith('that puts it under your usual 25.9 km a week, on purpose.');
});

it('returns to the floor the week after the guard lets go', function (): void {
    $user = flooredAthlete();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-09-27',
        'form_status' => 'overreaching',
        'monotony' => 1.0,
        'distance_km' => 30.0,
        'weekly_trimp' => 300.0,
        'atl_7d' => 50.0,
        'ctl_42d' => 40.0,
    ]);
    app(Periodizer::class)->regenerate($user, Carbon::today());

    Carbon::setTestNow('2026-09-28 08:00:00');
    WeeklySnapshot::query()->where('user_id', $user->id)->where('week_ending', '2026-09-27')->update(['form_status' => 'optimal', 'distance_km' => 16.0]);
    app(Periodizer::class)->regenerate($user, Carbon::today());

    expect(thisWeekPhase($user))->not->toBe(PlanPhase::Deload)
        ->and(thisWeekKm($user))->toBeGreaterThanOrEqual(25.91)
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->where('week_start', '2026-09-28')->value('volume_floor_km'))->toBeNull();
});

/**
 * Summary-first ingest writes these runs' distance at once, which is all the
 * floor reads, but their TRIMP only arrives with hydration, and the load guard
 * reads unscored runs as no signal.
 */
function unscoredRecentRuns(User $user): void
{
    foreach ([2, 9, 16, 23, 30] as $daysAgo) {
        $activity = Activity::factory()->summaryOnly()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::today()->subDays($daysAgo)->setTime(7, 0),
            'distance' => 10_000.0,
            'trimp_edwards' => null,
        ]);
    }
}

/** @return list<float> every stored Long day's km, in date order */
function storedLongRunsKm(User $user): array
{
    $sessions = PlannedSession::query()->where('user_id', $user->id)->orderBy('date')->get();
    $baseline = app(TrainingBaseline::class)->forUser($user, Carbon::today());
    $kmByDate = PlanRenderer::plannedKmByDate($sessions, $baseline['long_run_km'], $baseline['long_run_cap_km'], false, $baseline['long_run_progression_cap_km']);

    return array_values(array_map(
        fn (PlannedSession $s): float => $kmByDate[$s->date->toDateString()],
        $sessions->filter(fn (PlannedSession $s): bool => $s->session_type === SessionType::Long)->all(),
    ));
}

function storedMaxMultiplier(User $user): float
{
    return (float) PlannedSession::query()->where('user_id', $user->id)->max('volume_multiplier');
}

it('holds the block at the floor, with no ramp and no long-run climb, while recent load is unscored', function (): void {
    $user = flooredAthlete();
    unscoredRecentRuns($user);

    app(Periodizer::class)->regenerate($user, Carbon::today());

    $season = Season::query()->where('user_id', $user->id)->firstOrFail();
    $block = array_filter(
        app(SeasonSummaryBuilder::class)->plannedWeeks($user, $season),
        fn (array $week): bool => $week['zone'] === PhaseSchedule::ZONE_BLOCK,
    );
    $longRuns = storedLongRunsKm($user);
    $trainingWeeksKm = array_column(array_filter($block, fn (array $week): bool => ! in_array($week['phase'], [PlanPhase::Deload, PlanPhase::Taper], true)), 'planned_km');

    // Each training week matches the 25.91 km mean to the 0.1 km a session rounds to, and none goes past it.
    expect($season->increases_held)->toBeTrue()
        ->and(min($trainingWeeksKm))->toBeGreaterThanOrEqual(25.6)
        ->and(max(array_column($block, 'planned_km')))->toBeLessThanOrEqual(26.0)
        ->and(storedMaxMultiplier($user))->toBe(1.0)
        ->and(max($longRuns))->toBe($longRuns[0])
        ->and(max($longRuns))->toBeLessThan(12.0)
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->value('increases_held'))->toBeTrue()
        ->and(app(PlanPageAssembler::class)->adaptation($user, Carbon::today())['detail'])
        ->toEndWith('the build and the longer long runs wait until your recent runs are scored.');
});

it('lets the ramp and the long-run climb in at the next regeneration once that load is scored', function (): void {
    $user = flooredAthlete();
    unscoredRecentRuns($user);
    app(Periodizer::class)->regenerate($user, Carbon::today());

    Activity::query()->where('user_id', $user->id)->update(['ingest_state' => IngestState::Detailed]);
    Carbon::setTestNow('2026-09-28 00:26:00');
    app(Periodizer::class)->regenerate($user, Carbon::today());

    $longRuns = storedLongRunsKm($user);

    expect(Season::query()->where('user_id', $user->id)->value('increases_held'))->toBeFalse()
        ->and(storedMaxMultiplier($user))->toBeGreaterThan(1.0)
        ->and(max($longRuns))->toBe(11.0)
        ->and($longRuns[0])->toBeLessThan(11.0)
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->where('week_start', '2026-09-28')->value('increases_held'))->toBeFalse();
});
