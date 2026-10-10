<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\ExperienceLevel;
use App\Enums\FallOffTilt;
use App\Enums\PlanPhase;
use App\Enums\IngestState;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\RecoveryFeedback;
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
use App\Services\Run\Plan\TimeTrial;
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
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
        'distance' => 10_000,
        'start_date_local' => Carbon::parse('2026-09-19 07:00:00'),
    ]);
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

it('holds the race block at the athlete\'s own recent mean when load is fine, without lifting the progression cap to get there', function (): void {
    $user = flooredAthlete();

    app(Periodizer::class)->regenerate($user, Carbon::today());
    $season = Season::query()->where('user_id', $user->id)->firstOrFail();
    $block = array_values(array_filter(
        app(SeasonSummaryBuilder::class)->plannedWeeks($user, $season),
        fn (array $week): bool => $week['zone'] === PhaseSchedule::ZONE_BLOCK,
    ));

    expect($season->volume_floor_km)->toBe(25.91)
        ->and(app(TrainingBaseline::class)->forUser($user, $season->starts_at)['long_run_progression_cap_km'])->toBe(11.0)
        ->and(max(storedLongRunsKm($user)))->toBe(11.0)
        ->and(array_sum(array_column($block, 'planned_km')) / count($block))->toBeGreaterThanOrEqual(25.91)
        ->and(storedBlockWeeksKm($user, $season))->toHaveCount(12)
        ->and(array_sum(storedBlockWeeksKm($user, $season)) / 12)->toBeGreaterThanOrEqual(25.91)
        ->and(thisWeekKm($user))->toBeGreaterThanOrEqual(25.91)
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->value('volume_floor_km'))->toBeNull();
});

/**
 * A 45 km a week, five-session 10K runner whose fitted fall-off leans to
 * endurance, with taper quality days long enough to keep their structure.
 */
function tiltedAthlete(): User
{
    $user = User::factory()->create();
    foreach (range(0, 11) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::parse('2026-09-20')->subWeeks($i)->toDateString(),
            'distance_km' => 45.0,
            'runs' => 5,
            'form_status' => 'optimal',
        ]);
    }
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
        'distance' => 16_000,
        'moving_time' => 5_120,
        'elapsed_time' => 5_120,
        'start_date_local' => Carbon::parse('2026-09-19 07:00:00'),
    ]);
    TrainingPreference::query()->create([
        'user_id' => $user->id,
        'experience_level' => ExperienceLevel::Experienced,
        'sessions_per_week' => 5,
        'run_days' => [0, 1, 3, 5, 6],
        'long_run_day' => 6,
    ]);
    RaceGoal::factory()->for($user)->create(['race_date' => '2026-12-13', 'distance_m' => 10_000, 'goal_time_sec' => 3_000]);
    seedConfirmedEffort($user, 5_000, 1_200, Carbon::today()->subWeeks(3));
    seedConfirmedEffort($user, 15_000, (int) round(1_200 * 3 ** 1.13), Carbon::today()->subWeeks(2));

    return $user;
}

it('renders a fall-off-tilted block at the mean the floor solve lays out for it', function (): void {
    $user = tiltedAthlete();

    app(Periodizer::class)->regenerate($user, Carbon::today());
    $season = Season::query()->where('user_id', $user->id)->firstOrFail();
    $trialOrEasedWeeks = PlannedSession::query()->where('user_id', $user->id)->get()
        ->filter(fn (PlannedSession $s): bool => TimeTrial::isTrial($s->prescription_race_context) || str_starts_with((string) $s->prescription_reason, 'easy because'))
        ->map(fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString())->unique()->all();
    $block = collect(app(SeasonSummaryBuilder::class)->plannedWeeks($user, $season))
        ->filter(fn (array $week): bool => $week['zone'] === PhaseSchedule::ZONE_BLOCK)
        ->keyBy(fn (array $week): string => $week['week_start']->toDateString())
        ->except($trialOrEasedWeeks);
    $stored = collect(storedBlockWeeksKm($user, $season))->only($block->keys()->all());

    expect(PlannedSession::query()->where('user_id', $user->id)->where('session_type', SessionType::Long)->where('fall_off_tilt', FallOffTilt::Endurance)->exists())->toBeTrue()
        ->and($block->pluck('phase')->unique()->values()->all())->toContain(PlanPhase::Build, PlanPhase::Peak)
        ->and($stored->keys()->all())->toBe($block->keys()->all())
        ->and($stored->avg())->toEqualWithDelta($block->avg('planned_km'), 0.05);
});

it('renders every block week without a trial or an eased taper day at its predicted km, and those weeks hold the floor', function (): void {
    $user = tiltedAthlete();

    app(Periodizer::class)->regenerate($user, Carbon::today());
    $season = Season::query()->where('user_id', $user->id)->firstOrFail();
    $sessions = PlannedSession::query()->where('user_id', $user->id)->get();
    $weeksHolding = fn (Closure $holds): array => $sessions->filter($holds)
        ->map(fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString())->unique()->values()->all();
    $trialWeeks = $weeksHolding(fn (PlannedSession $s): bool => TimeTrial::isTrial($s->prescription_race_context));
    $easedTaperWeeks = $weeksHolding(fn (PlannedSession $s): bool => $s->phase === PlanPhase::Taper && in_array($s->prescription_reason, [
        'easy because the outing cannot safely fit the minimum quality structure',
        'easy because the week has no safe room for meaningful quality',
    ], true));
    $predicted = collect(app(SeasonSummaryBuilder::class)->plannedWeeks($user, $season))
        ->filter(fn (array $week): bool => $week['zone'] === PhaseSchedule::ZONE_BLOCK)
        ->mapWithKeys(fn (array $week): array => [$week['week_start']->toDateString() => $week['planned_km']]);
    $block = collect(storedBlockWeeksKm($user, $season));
    $stored = $block->except([...$trialWeeks, ...$easedTaperWeeks]);

    expect($block->only($trialWeeks))->not->toBeEmpty()
        ->and($block->only($easedTaperWeeks))->not->toBeEmpty()
        ->and($stored)->not->toBeEmpty();
    foreach ($stored as $weekStart => $km) {
        expect($km)->toEqualWithDelta($predicted[$weekStart], 0.05);
    }
    expect($stored->avg())->toBeGreaterThanOrEqual($season->volume_floor_km);
});

it('renders a block on the athlete\'s own run days at the mean the floor solve lays out for it', function (): void {
    $user = flooredAthlete();
    TrainingPreference::query()->where('user_id', $user->id)->update(['run_days' => [0, 2, 4, 6]]);

    app(Periodizer::class)->regenerate($user, Carbon::today());
    $season = Season::query()->where('user_id', $user->id)->firstOrFail();
    $block = array_filter(
        app(SeasonSummaryBuilder::class)->plannedWeeks($user, $season),
        fn (array $week): bool => $week['zone'] === PhaseSchedule::ZONE_BLOCK,
    );
    $stored = storedBlockWeeksKm($user, $season);

    expect($stored)->toHaveCount(count($block))
        ->and(array_sum($stored) / count($stored))->toEqualWithDelta(array_sum(array_column($block, 'planned_km')) / count($block), 0.05)
        ->and(array_sum($stored) / count($stored))->toBeGreaterThanOrEqual(25.91);
});

it('backs off under the floor for a strong current concern, and says so on the plan', function (): void {
    $user = flooredAthlete();
    RecoveryFeedback::query()->create([
        'user_id' => $user->id,
        'date' => Carbon::today()->toDateString(),
        'illness' => true,
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
    RecoveryFeedback::query()->create([
        'user_id' => $user->id,
        'date' => Carbon::today()->toDateString(),
        'illness' => true,
    ]);
    app(Periodizer::class)->regenerate($user, Carbon::today());

    Carbon::setTestNow('2026-09-28 08:00:00');
    RecoveryFeedback::query()->create([
        'user_id' => $user->id,
        'date' => Carbon::today()->toDateString(),
        'illness' => false,
    ]);
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

/** @return array<string, float> each stored block week's rendered km, keyed by its Monday */
function storedBlockWeeksKm(User $user, Season $season): array
{
    $blockWeeks = array_map(
        fn (array $week): string => $week['week_start']->toDateString(),
        array_filter(app(SeasonSummaryBuilder::class)->plannedWeeks($user, $season), fn (array $week): bool => $week['zone'] === PhaseSchedule::ZONE_BLOCK),
    );
    $sessions = PlannedSession::query()->where('user_id', $user->id)->orderBy('date')->get();
    $baseline = app(TrainingBaseline::class)->forUser($user, Carbon::today());
    $kmByDate = PlanRenderer::plannedKmByDate($sessions, $baseline['long_run_km'], $baseline['long_run_cap_km'], false, $baseline['long_run_progression_cap_km']);

    $kmByWeek = [];
    foreach ($sessions as $session) {
        $weekStart = $session->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        if (in_array($weekStart, $blockWeeks, true)) {
            $kmByWeek[$weekStart] = ($kmByWeek[$weekStart] ?? 0.0) + $kmByDate[$session->date->toDateString()];
        }
    }

    return $kmByWeek;
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

    expect($season->increases_held)->toBeTrue()
        ->and(array_sum($trainingWeeksKm) / count($trainingWeeksKm))->toBeGreaterThanOrEqual(25.91)
        ->and(max($trainingWeeksKm))->toBeLessThanOrEqual(25.91 * 1.10)
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
