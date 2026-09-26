<?php

declare(strict_types=1);

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Actions\Run\Plan\ResolveSeasonAction;
use App\Enums\IngestState;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\SeasonGoal;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\SeasonService;
use App\Services\Run\Plan\SeasonSummaryBuilder;
use App\Services\Run\Plan\PhaseSchedule;
use App\Services\Run\Plan\SegmentGenerator;
use App\Services\Run\Plan\TrainingBaseline;
use App\Enums\SessionType;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->service = app(SeasonService::class);
});
afterEach(fn () => Carbon::setTestNow());

it('creates a self-scaled 12-week season for a user with no active race', function (): void {
    $user = User::factory()->create();

    $season = $this->service->ensureCurrent($user, Carbon::today());

    expect($season->race_goal_id)->toBeNull()
        ->and($season->starts_at->toDateString())->toBe('2026-08-10')
        ->and($season->ends_at->toDateString())->toBe(Carbon::parse('2026-08-10')->addWeeks(12)->toDateString());
});

it('creates a race-oriented season ending on race day when an active race exists', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(9)->toDateString()]);

    $season = $this->service->ensureCurrent($user, Carbon::today());

    expect($season->race_goal_id)->toBe($race->id)
        ->and($season->ends_at->toDateString())->toBe($race->race_date->toDateString());
});

it('generates exactly 5 season goals', function (): void {
    $user = User::factory()->create();

    $season = $this->service->ensureCurrent($user, Carbon::today());

    expect(SeasonGoal::query()->where('season_id', $season->id)->count())->toBe(5);
});

it('generates a race-margin goal for a race-oriented season and a CTL-growth goal for self-scaled', function (): void {
    $user = User::factory()->create();
    $season = $this->service->ensureCurrent($user, Carbon::today());
    $metrics = SeasonGoal::query()->where('season_id', $season->id)->pluck('metric')->all();
    expect($metrics)->toContain('season_ctl_growth')->not->toContain('season_race_goal_met');

    $userWithRace = User::factory()->create();
    RaceGoal::factory()->for($userWithRace)->create(['race_date' => Carbon::today()->addWeeks(9)->toDateString()]);
    $raceSeason = $this->service->ensureCurrent($userWithRace, Carbon::today());
    $raceMetrics = SeasonGoal::query()->where('season_id', $raceSeason->id)->pluck('metric')->all();
    expect($raceMetrics)->toContain('season_race_goal_met')->not->toContain('season_ctl_growth');
});

it('returns the same season on a second call the same day, without duplicating goals', function (): void {
    $user = User::factory()->create();

    $first = $this->service->ensureCurrent($user, Carbon::today());
    $second = $this->service->ensureCurrent($user, Carbon::today());

    expect($second->id)->toBe($first->id)
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(SeasonGoal::query()->where('season_id', $first->id)->count())->toBe(5);
});

it('ends a self-scaled season early and starts a race-oriented one when a race is set mid-season', function (): void {
    $user = User::factory()->create();
    $selfScaled = $this->service->ensureCurrent($user, Carbon::today());

    Carbon::setTestNow('2026-08-17 08:00:00'); // a week later
    RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(9)->toDateString()]);

    $raceOriented = $this->service->ensureCurrent($user, Carbon::today());

    expect($raceOriented->id)->not->toBe($selfScaled->id)
        ->and($raceOriented->race_goal_id)->not->toBeNull()
        ->and($selfScaled->fresh()->ends_at->toDateString())->toBe('2026-08-16')
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('keeps a race season\'s ends_at in sync with the race date when the same race row moves, without changing the season identity', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(9)->toDateString()]);
    $season = $this->service->ensureCurrent($user, Carbon::today());
    expect($season->ends_at->toDateString())->toBe($race->race_date->toDateString());

    // The race is edited in place (same row, same id) rather than superseded.
    $race->update(['race_date' => Carbon::today()->addWeeks(13)->toDateString()]);

    $resynced = $this->service->ensureCurrent($user, Carbon::today());

    expect($resynced->id)->toBe($season->id)
        ->and($resynced->race_goal_id)->toBe($race->id)
        ->and($resynced->ends_at->toDateString())->toBe($race->fresh()->race_date->toDateString());
});

it('retargets the season in place, rather than opening a duplicate row, when the race is set the same day the season started', function (): void {
    $user = User::factory()->create();
    $selfScaled = $this->service->ensureCurrent($user, Carbon::today());
    $race = RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(3)->toDateString()]);

    $season = $this->service->ensureCurrent($user, Carbon::today());

    expect($season->id)->toBe($selfScaled->id)
        ->and($season->race_goal_id)->toBe($race->id)
        ->and($season->ends_at->toDateString())->toBe($race->race_date->toDateString())
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(SeasonGoal::query()->where('season_id', $season->id)->pluck('metric')->all())->toEqualCanonicalizing([
            'season_sessions_completed',
            'season_quality_completed',
            'season_longest_long_run_km',
            'season_rest_honored',
            'season_race_goal_met',
            'season_peak_weekly_km',
        ]);
});

it('starts a new self-scaled season when the active race is cleared mid-season', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(9)->toDateString()]);
    $raceOriented = $this->service->ensureCurrent($user, Carbon::today());

    Carbon::setTestNow('2026-08-17 08:00:00');
    // What RaceController::destroy() does: a mass update fires no model events,
    // so both the shared-prop cache and the per-request memo are dropped by hand.
    RaceGoal::query()->where('user_id', $user->id)->update(['completed_at' => now()]);
    app(ResolveActiveRaceAction::class)->forget($user->id);

    $selfScaled = $this->service->ensureCurrent($user, Carbon::today());

    expect($selfScaled->id)->not->toBe($raceOriented->id)
        ->and($selfScaled->race_goal_id)->toBeNull();
});

it('auto-cycles a self-scaled season into a fresh one once it expires, without overlapping the old one', function (): void {
    $user = User::factory()->create();
    $first = $this->service->ensureCurrent($user, Carbon::today());

    Carbon::setTestNow($first->ends_at->copy()->addDay()->format('Y-m-d H:i:s'));
    $second = $this->service->ensureCurrent($user, Carbon::today());

    expect($second->id)->not->toBe($first->id)
        ->and($second->starts_at->toDateString())->toBe(Carbon::today()->toDateString())
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(2)
        // No gap and no overlap: the new season starts exactly where the old one ended.
        ->and($first->fresh()->ends_at->toDateString())->toBe(Carbon::today()->copy()->subDay()->toDateString());
});

it('peeks null when the user has no season yet, without creating one', function (): void {
    $user = User::factory()->create();

    expect($this->service->peekCurrent($user, Carbon::today()))->toBeNull()
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('peeks the same season ensureCurrent already created, without mutating it', function (): void {
    $user = User::factory()->create();
    $created = $this->service->ensureCurrent($user, Carbon::today());

    $peeked = $this->service->peekCurrent($user, Carbon::today());

    expect($peeked)->not->toBeNull()
        ->and($peeked->id)->toBe($created->id)
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('peeks null once a self-scaled season has expired, rather than treating it as still current', function (): void {
    $user = User::factory()->create();
    $season = $this->service->ensureCurrent($user, Carbon::today());

    Carbon::setTestNow($season->ends_at->copy()->addDay()->format('Y-m-d H:i:s'));

    expect($this->service->peekCurrent($user, Carbon::today()))->toBeNull();
});

it('scales the quality-session target with the athlete\'s own trailing session count', function (): void {
    $user = User::factory()->create();
    foreach (range(0, 3) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => 6,
            'distance_km' => 50.0,
        ]);
    }

    $season = $this->service->ensureCurrent($user, Carbon::today());
    $qualityGoal = SeasonGoal::query()->where('season_id', $season->id)->where('metric', 'season_quality_completed')->first();

    // A 6-runs/week athlete gets 2 quality slots/week across a 12-week build/deload
    // cycle (3 build weeks at 2 slots + 1 deload week at 0, repeated 3x) = 18.
    expect($qualityGoal->target)->toBe(18.0);
});

it('counts a race season\'s general-zone weeks at their reduced, base-rule quality slot count', function (): void {
    $user = User::factory()->create();
    foreach (range(0, 5) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => 6,
            'distance_km' => 60.0,
        ]);
    }
    RaceGoal::factory()->for($user)->create(['race_date' => '2027-03-08', 'distance_m' => 10_000]);

    $season = $this->service->ensureCurrent($user, Carbon::today());
    $qualityGoal = SeasonGoal::query()->where('season_id', $season->id)->where('metric', 'season_quality_completed')->first();

    // 31-week arc: 15 general weeks (12 Build @ 1 slot + 3 Deload @ 0) = 12,
    // 16 block weeks (3 Base @ 1 + 2 Deload @ 0 + 6 Build @ 2 + 4 Peak @ 2 +
    // 1 Taper @ 2) = 25. A general-zone Build week no longer counts the
    // block's race-mode 2-slot mix.
    expect($qualityGoal->target)->toBe(37.0);
});

it('respects an explicit sessions_per_week preference below the old behavioral floor of 3', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 2, 'run_days' => null, 'long_run_day' => null]);

    $season = $this->service->ensureCurrent($user, Carbon::today());
    $sessionsGoal = SeasonGoal::query()->where('season_id', $season->id)->where('metric', 'season_sessions_completed')->first();

    expect($sessionsGoal->target)->toBe(24.0); // 2 sessions/week * 12-week self-scaled horizon
});

function seasonServiceWeeks(User $user, float $km): void
{
    foreach (range(0, 5) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => 4,
            'distance_km' => $km,
        ]);
    }
}

/** The longest long run the season's own arc prescribes, sized the way the plan sizes it. */
function planLongestLongRunKm(User $user, RaceGoal $race): float
{
    $baselineData = app(TrainingBaseline::class)->forUser($user, Carbon::today());
    $weeks = new PhaseSchedule()->forRace(Carbon::today(), $race->race_date, (float) $race->distance_m);
    $multipliers = PhaseSchedule::volumeMultipliers(array_column($weeks, 'phase'), zones: array_column($weeks, 'zone'));

    return round(max(array_map(
        fn (float $multiplier): float => SegmentGenerator::coreKmFor(SessionType::Long, false, $baselineData['long_run_km'], $multiplier, $baselineData['long_run_cap_km']),
        $multipliers,
    )), 1);
}

it('opens a race season far from its block with the general goals only, its long-run goal the plan\'s own longest', function (int $distanceM, float $weeklyKm): void {
    $user = User::factory()->create();
    seasonServiceWeeks($user, $weeklyKm);
    $race = RaceGoal::factory()->for($user)->create(['race_date' => '2027-03-08', 'distance_m' => $distanceM]);

    $season = $this->service->ensureCurrent($user, Carbon::today());
    $goals = SeasonGoal::query()->where('season_id', $season->id)->pluck('target', 'metric')->all();

    expect(array_keys($goals))->toEqualCanonicalizing([
        'season_sessions_completed',
        'season_quality_completed',
        'season_longest_long_run_km',
        'season_rest_honored',
    ])
        ->and($goals['season_longest_long_run_km'])->toBe(planLongestLongRunKm($user, $race));
})->with([
    '10K' => [10_000, 40.0],
    'half' => [21_097, 40.0],
    'marathon' => [42_195, 80.0],
    '10K capped at half the week' => [10_000, 16.0],
]);

/**
 * The audit case: the goal asked for a 12 km long run while the plan peaked at
 * 10.1 km. The goal now reads the plan, and the plan climbs to the 12 km a 10K
 * wants.
 */
it('asks for exactly the long run the plan builds to, which reaches a 10K\'s 12 km', function (): void {
    $user = User::factory()->create();
    seasonServiceWeeks($user, 24.4);
    RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(11)->toDateString(), 'distance_m' => 10_000]);

    $season = $this->service->ensureCurrent($user, Carbon::today());
    $goal = SeasonGoal::query()->where('season_id', $season->id)->where('metric', 'season_longest_long_run_km')->value('target');

    expect($goal)->toBe(12.0)
        ->and($goal)->toBe(planLongestLongRunKm($user, $season->raceGoal));
});

it('keeps the season long-run goal aimed at the arc beyond the first staged step', function (): void {
    $user = User::factory()->create();
    seasonServiceWeeks($user, 24.4);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDay(),
        'distance' => 5_000.0,
    ]);
    RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(11)->toDateString(), 'distance_m' => 10_000]);

    $season = $this->service->ensureCurrent($user, Carbon::today());
    $goal = SeasonGoal::query()->where('season_id', $season->id)->where('metric', 'season_longest_long_run_km')->value('target');

    expect($goal)->toBe(12.0)
        ->and($goal)->toBeGreaterThan(app(TrainingBaseline::class)->forUser($user, Carbon::today())['long_run_progression_cap_km']);
});

it('freezes a race season\'s twelve-week actual mean as its volume floor, and gives a self-scaled one none', function (): void {
    $racer = User::factory()->create();
    foreach (range(0, 13) as $i) {
        WeeklySnapshot::factory()->for($racer)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'distance_km' => $i < 12 ? 20.0 + $i : 100.0,
        ]);
    }
    RaceGoal::factory()->for($racer)->create(['race_date' => Carbon::today()->addWeeks(11)->toDateString()]);
    $goalless = User::factory()->create();
    seasonServiceWeeks($goalless, 30.0);

    // 20..31 km, the two older 100 km weeks outside the window.
    expect($this->service->ensureCurrent($racer, Carbon::today())->volume_floor_km)->toBe(25.5)
        ->and($this->service->ensureCurrent($goalless, Carbon::today())->volume_floor_km)->toBeNull();
});

it('backfills a race season\'s missing floor from the weeks before it opened', function (): void {
    $user = User::factory()->create();
    seasonServiceWeeks($user, 30.0);
    $race = RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(11)->toDateString()]);
    $season = Season::factory()->for($user)->create([
        'race_goal_id' => $race->id,
        'anchor_weekly_volume_km' => 30.0,
        'starts_at' => Carbon::today()->toDateString(),
        'ends_at' => $race->race_date->toDateString(),
    ]);

    Carbon::setTestNow('2026-08-24 08:00:00');
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-08-16', 'distance_km' => 60.0]);

    expect($this->service->ensureCurrent($user, Carbon::today())->volume_floor_km)->toBe(30.0)
        ->and($season->fresh()->volume_floor_km)->toBe(30.0);
});

it('brings the floor down to the new anchor when the athlete\'s volume collapses', function (): void {
    $user = User::factory()->create();
    seasonServiceWeeks($user, 30.0);
    RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(11)->toDateString()]);
    $season = $this->service->ensureCurrent($user, Carbon::today());
    expect($season->volume_floor_km)->toBe(30.0);

    Carbon::setTestNow('2026-09-21 08:00:00');
    foreach (range(0, 5) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::parse('2026-09-20')->subWeeks($i)->toDateString(),
            'distance_km' => 10.0,
        ]);
    }

    expect($this->service->ensureCurrent($user, Carbon::today())->volume_floor_km)->toBe(10.0);
});

it('appends the block goals once the block opens, and only once', function (): void {
    $user = User::factory()->create();
    seasonServiceWeeks($user, 30.0);
    RaceGoal::factory()->for($user)->create(['race_date' => '2027-03-08', 'distance_m' => 10_000]);
    $season = $this->service->ensureCurrent($user, Carbon::today());

    Carbon::setTestNow('2026-11-22 08:00:00');
    $this->service->ensureCurrent($user, Carbon::today());
    expect(SeasonGoal::query()->where('season_id', $season->id)->count())->toBe(4);

    Carbon::setTestNow('2026-11-23 08:00:00');
    $this->service->ensureCurrent($user, Carbon::today());

    $goalQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$goalQueries): void {
        $goalQueries += str_contains($query->sql, 'season_goals') ? 1 : 0;
    });
    $this->service->ensureCurrent($user, Carbon::today());
    expect($goalQueries)->toBe(0)
        ->and($season->fresh()->block_goals_appended_at)->not->toBeNull();

    $metrics = SeasonGoal::query()->where('season_id', $season->id)->pluck('metric');
    $peakWeekKm = collect(app(SeasonSummaryBuilder::class)->plannedWeeks($user, $season->fresh()))
        ->where('zone', 'block')
        ->max('planned_km');

    expect($metrics)->toHaveCount(6)
        ->and($metrics->duplicates())->toBeEmpty()
        ->and($metrics)->toContain('season_race_goal_met', 'season_peak_weekly_km')
        ->and(SeasonGoal::query()->where('season_id', $season->id)->where('metric', 'season_peak_weekly_km')->value('target'))
        ->toBe(round($peakWeekKm, 1));
});

it('serves the under-ready line once, counting the block rows through race week', function (int $daysOut, int $distanceM, string $line): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addDays($daysOut)->toDateString(), 'distance_m' => $distanceM]);
    $season = $this->service->ensureCurrent($user, Carbon::today());

    expect($this->service->takeUnderReadyLine($season))->toBe($line)
        ->and($season->fresh()->under_ready_noted_at)->not->toBeNull()
        ->and($this->service->takeUnderReadyLine($season->fresh()))->toBeNull();
})->with([
    '10K with twelve rows to race week' => [77, 10_000, "Twelve weeks is tighter than I'd pick for this one, so we build what we can and race what we've built."],
    'marathon with eighteen rows to race week' => [119, 42_195, "Eighteen weeks is tighter than I'd pick for this one, so we build what we can and race what we've built."],
    'half raced in the season\'s first week' => [5, 21_097, "One week is tighter than I'd pick for this one, so we build what we can and race what we've built."],
]);

it('says nothing about readiness for a full block or a season with no race', function (): void {
    $fullBlock = User::factory()->create();
    RaceGoal::factory()->for($fullBlock)->create(['race_date' => Carbon::today()->addWeeks(15)->toDateString(), 'distance_m' => 10_000]);
    $farOut = User::factory()->create();
    RaceGoal::factory()->for($farOut)->create(['race_date' => Carbon::today()->addWeeks(30)->toDateString(), 'distance_m' => 42_195]);

    foreach ([$fullBlock, $farOut, User::factory()->create()] as $user) {
        $season = $this->service->ensureCurrent($user, Carbon::today());

        expect($this->service->takeUnderReadyLine($season))->toBeNull()
            ->and($season->fresh()->under_ready_noted_at)->toBeNull();
    }
});

function unscoredRunOn(User $user, Carbon $day): Activity
{
    $activity = Activity::factory()->summaryOnly()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => $day->copy()->setTime(7, 0)]);

    return $activity;
}

it('holds a race season\'s increases when a run inside the chronic-load window is still unscored', function (): void {
    $held = User::factory()->create();
    RaceGoal::factory()->for($held)->create(['race_date' => Carbon::today()->addWeeks(11)->toDateString()]);
    unscoredRunOn($held, Carbon::today()->subDays(41));
    $outsideWindow = User::factory()->create();
    RaceGoal::factory()->for($outsideWindow)->create(['race_date' => Carbon::today()->addWeeks(11)->toDateString()]);
    unscoredRunOn($outsideWindow, Carbon::today()->subDays(42));
    $goalless = User::factory()->create();
    unscoredRunOn($goalless, Carbon::today()->subDay());

    expect($this->service->ensureCurrent($held, Carbon::today())->increases_held)->toBeTrue()
        ->and($this->service->ensureCurrent($outsideWindow, Carbon::today())->increases_held)->toBeFalse()
        ->and($this->service->ensureCurrent($goalless, Carbon::today())->increases_held)->toBeFalse();
});

it('releases held increases only once the window is scored, and never on an ordinary read', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(11)->toDateString()]);
    $run = unscoredRunOn($user, Carbon::today()->subDay());
    $season = $this->service->ensureCurrent($user, Carbon::today());

    $this->service->releaseHeldIncreases($season, $user, Carbon::today());
    $stillHeld = $season->fresh()->increases_held;

    $run->update(['ingest_state' => IngestState::Detailed]);
    $this->service->ensureCurrent($user, Carbon::today());
    $heldAfterRead = $season->fresh()->increases_held;

    $this->service->releaseHeldIncreases($season, $user, Carbon::today());

    expect($stillHeld)->toBeTrue()
        ->and($heldAfterRead)->toBeTrue()
        ->and($season->fresh()->increases_held)->toBeFalse();
});

it('re-reads under the athlete lock, so a caller holding a stale "no season" read reuses the season another caller opened', function (): void {
    $user = User::factory()->create();
    $callerA = app()->make(SeasonService::class, ['season' => new ResolveSeasonAction()]);
    expect($callerA->peekCurrent($user, Carbon::today()))->toBeNull();

    $openedByB = app(SeasonService::class)->ensureCurrent($user, Carbon::today());
    $seenByA = $callerA->ensureCurrent($user, Carbon::today());

    expect($seenByA->id)->toBe($openedByB->id)
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(SeasonGoal::query()->where('season_id', $openedByB->id)->count())->toBe(5);
});

it('appends each block goal once when a second caller still holds the pre-append season', function (): void {
    $user = User::factory()->create();
    seasonServiceWeeks($user, 30.0);
    RaceGoal::factory()->for($user)->create(['race_date' => '2027-03-08', 'distance_m' => 10_000]);
    $season = $this->service->ensureCurrent($user, Carbon::today());

    Carbon::setTestNow('2026-11-23 08:00:00');
    $callerA = app()->make(SeasonService::class, ['season' => new ResolveSeasonAction()]);
    expect($callerA->peekCurrent($user, Carbon::today())?->block_goals_appended_at)->toBeNull();

    app(SeasonService::class)->ensureCurrent($user, Carbon::today());
    $callerA->ensureCurrent($user, Carbon::today());

    $metrics = SeasonGoal::query()->where('season_id', $season->id)->pluck('metric');
    expect($metrics)->toHaveCount(6)
        ->and($metrics->duplicates())->toBeEmpty();
});

it('returns the existing season when a create still hits the seasons unique index', function (): void {
    $user = User::factory()->create();
    $existing = $this->service->ensureCurrent($user, Carbon::today());

    $staleRead = Mockery::mock(ResolveSeasonAction::class)->makePartial();
    $staleRead->shouldReceive('latest')->with($user->id)->twice()->andReturn(null, $existing);
    $season = app()->make(SeasonService::class, ['season' => $staleRead])->ensureCurrent($user, Carbon::today());

    expect($season->id)->toBe($existing->id)
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(SeasonGoal::query()->where('season_id', $existing->id)->count())->toBe(5);
});
