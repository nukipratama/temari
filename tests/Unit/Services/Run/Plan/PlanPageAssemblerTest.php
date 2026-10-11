<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\IngestState;
use App\Enums\PlannedSessionStatus;
use App\Enums\RaceOutcome;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlanAdaptation;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanPageAssembler;
use App\Services\Run\Plan\PlanRenderer;
use App\Services\Run\Plan\RaceAmbitionAssessor;
use App\Services\Run\Plan\SessionEditRules;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->assembler = app(PlanPageAssembler::class);
});
afterEach(fn () => Carbon::setTestNow());

function assemblerAthlete(): User
{
    $user = User::factory()->create();
    foreach (range(0, 3) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => 4,
            'distance_km' => 30.0,
        ]);
    }

    return $user;
}

/** @return array<string, mixed> */
function assemblerShownReadiness(array $day): array
{
    return json_decode(Crypt::decryptString($day['recommendation_token']), true, flags: JSON_THROW_ON_ERROR)['effective']['readiness_assessment'];
}

it('returns no race payload when the athlete has no active goal', function (): void {
    expect($this->assembler->race(assemblerAthlete()))->toBeNull();
});

it('names the active race and the day it is run', function (): void {
    $user = assemblerAthlete();
    RaceGoal::factory()->for($user)->create([
        'race_date' => '2026-10-31',
        'distance_m' => 10_000,
        'name' => 'City 10K',
    ]);

    expect($this->assembler->race($user))->toBe(['race_date' => '2026-10-31', 'name' => 'City 10K']);
});

it('ensures the season once even though three props ask for it', function (): void {
    $user = assemblerAthlete();
    $today = Carbon::today();

    $this->assembler->season($user, $today);
    $this->assembler->seasonSummary($user, $today);
    $this->assembler->seasonAdherencePct($user, $today);

    expect(Season::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('carries the under-ready line on the first Plan visit of a short block, and not after', function (): void {
    $user = assemblerAthlete();
    RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-26', 'distance_m' => 10_000]);
    $today = Carbon::today();

    expect($this->assembler->season($user, $today)['under_ready_line'])
        ->toBe("Twelve weeks is tighter than I'd pick for this one, so we build what we can and race what we've built.")
        ->and($this->assembler->season($user, $today)['under_ready_line'])->toBeNull();
});

it('has nothing to explain about a week the adapter never touched', function (): void {
    expect($this->assembler->adaptation(assemblerAthlete(), Carbon::today()))->toBeNull();
});

it('explains the current week in the adapter\'s own words', function (): void {
    $user = assemblerAthlete();
    PlanAdaptation::query()->create([
        'user_id' => $user->id,
        'week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
        'reason' => AdaptationReason::MissedWeek,
        'deload' => true,
        'quality_delta' => -1,
        'adherence_pct' => 0,
    ]);

    expect($this->assembler->adaptation($user, Carbon::today()))->toBe([
        'reason' => AdaptationReason::MissedWeek->value,
        'headline' => AdaptationReason::MissedWeek->headline(),
        'detail' => AdaptationReason::MissedWeek->detail(0),
        'deload' => true,
    ]);
});

it('says so when the deload takes the week under the season\'s volume floor', function (): void {
    $user = assemblerAthlete();
    PlanAdaptation::query()->create([
        'user_id' => $user->id,
        'week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
        'reason' => AdaptationReason::LowReadiness,
        'deload' => true,
        'quality_delta' => 0,
        'adherence_pct' => 100,
        'volume_floor_km' => 25.91,
    ]);

    expect($this->assembler->adaptation($user, Carbon::today())['detail'])
        ->toBe(AdaptationReason::LowReadiness->detail(100).' that puts it under your usual 25.9 km a week, on purpose.');
});

it('explains volume and stimulus adherence separately when key work misses', function (): void {
    $user = assemblerAthlete();
    PlanAdaptation::query()->create([
        'user_id' => $user->id,
        'week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
        'reason' => AdaptationReason::MissedStimulus,
        'deload' => false,
        'quality_delta' => 0,
        'adherence_pct' => 100,
        'stimulus_adherence_pct' => 50,
    ]);

    expect($this->assembler->adaptation($user, Carbon::today())['detail'])
        ->toContain("you ran all of last week's distance, but only half of the latest settled key work landed")
        ->toContain('does not add another quality session');
});

it('says the build is waiting when the block holds its increases for unscored load', function (): void {
    $user = assemblerAthlete();
    PlanAdaptation::query()->create([
        'user_id' => $user->id,
        'week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
        'reason' => AdaptationReason::Steady,
        'deload' => false,
        'quality_delta' => 0,
        'adherence_pct' => 100,
        'increases_held' => true,
    ]);

    expect($this->assembler->adaptation($user, Carbon::today())['detail'])
        ->toBe(AdaptationReason::Steady->detail(100).' the build and the longer long runs wait until your recent runs are scored.');
});

it('has no weeks to render before anything has been planned', function (): void {
    expect($this->assembler->weeks(assemblerAthlete(), Carbon::today()))->toBe([]);
});

it('renders the generated weeks with the current one marked as such', function (): void {
    $user = assemblerAthlete();
    app(Periodizer::class)->regenerate($user, Carbon::today());

    $weeks = $this->assembler->weeks($user, Carbon::today());
    $current = collect($weeks)->firstWhere('type', 'current');

    expect($weeks)->not->toBeEmpty()
        ->and($current['week_start'])->toBe(Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString())
        ->and($current['days'])->toHaveCount(7);
});

it('keeps a recommendation token on every plan day and ships no readiness assessment beside it', function (): void {
    $user = assemblerAthlete();
    app(Periodizer::class)->regenerate($user, Carbon::today());

    $days = collect($this->assembler->weeks($user, Carbon::today()))->pluck('days')->flatten(1);

    expect($days->count())->toBeGreaterThan(28)
        ->and($days->every(fn (array $day): bool => is_string($day['recommendation_token']) && $day['recommendation_token'] !== ''))->toBeTrue()
        ->and($days->contains(fn (array $day): bool => array_key_exists('readiness_assessment', $day)))->toBeFalse();
});

it('reads the briefing context once for the weeks and the season summary', function (): void {
    $user = assemblerAthlete();
    app(Periodizer::class)->regenerate($user, Carbon::today());
    $today = Carbon::today();
    $this->assembler->season($user, $today);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->assembler->weeks($user, $today);
    $this->assembler->seasonSummary($user, $today);

    $stressReads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '`activity_details`.`has_heartrate`'))->count();
    DB::disableQueryLog();

    expect($stressReads)->toBe(1);
});

it('offers the same edit actions and move targets as scanning every row per day', function (): void {
    $user = assemblerAthlete();
    app(Periodizer::class)->regenerate($user, Carbon::today());
    $rows = PlannedSession::query()->where('user_id', $user->id)->orderBy('date')->get();
    $today = Carbon::today();

    $days = collect($this->assembler->weeks($user, $today))->pluck('days')->flatten(1);

    expect($days->count())->toBeGreaterThan(28);
    foreach ($days as $day) {
        $row = $rows->first(fn (PlannedSession $r): bool => $r->date->toDateString() === $day['date']);
        [$from, $to] = SessionEditRules::window($row->date);
        $window = $rows->filter(fn (PlannedSession $r): bool => $r->date->betweenIncluded($from, $to));
        $status = PlannedSessionStatus::from($day['status']);
        $targets = SessionEditRules::canMoveFrom($row, $status, $today) ? SessionEditRules::moveTargets($row, $window, [], $today) : [];

        expect($day['move_targets'])->toBe($targets)
            ->and($day['actions']['move'])->toBe($targets !== []);
    }
    expect($days->contains(fn (array $day): bool => $day['actions']['move'] && $day['move_targets'] !== []))->toBeTrue();
});

it('renders ran_anyway true for a past, unscored rest day with a logged run', function (): void {
    $user = assemblerAthlete();
    $restDay = Carbon::today()->subWeek();
    PlannedSession::factory()->for($user)->create([
        'date' => $restDay->toDateString(),
        'session_type' => SessionType::Rest,
    ]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $restDay->copy()->setTime(7, 0),
        'distance' => 5_000.0,
    ]);

    $day = collect($this->assembler->weeks($user, Carbon::today()))
        ->pluck('days')
        ->flatten(1)
        ->firstWhere('date', $restDay->toDateString());

    expect($day['status'])->toBe('done')
        ->and($day['ran_anyway'])->toBeTrue();
});

/** A tempo day eased to easy at 00:01, distance held: the Plan row leads with the easy run, tempo as context. */
it('leads the Plan row today with a tempo day eased to easy, tempo only as context', function (): void {
    $user = assemblerAthlete();
    $row = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Tempo,
    ]);
    $baseline = app(TrainingBaseline::class)->forUser($user, Carbon::today());
    $storedKm = PlanRenderer::coreKmForSession($row, $baseline['long_run_km'], $baseline['long_run_cap_km'], $baseline['self_scaled'], $baseline['long_run_progression_cap_km']);
    $row->update(['clamped_km' => $storedKm]);

    $day = collect($this->assembler->weeks($user, Carbon::today()))
        ->firstWhere('type', 'current')['days'][0];

    expect($day['session_type'])->toBe('easy')
        ->and($day['distance_km'])->toBe($storedKm)
        ->and($day['eased_from']['session_type'])->toBe('tempo')
        ->and($day['eased_from']['voice'])->not->toBeNull();
});

it('holds todays advisory clamp while a demanding run awaits hydration, then applies its evidence', function (): void {
    $user = assemblerAthlete();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'form_status' => 'overreaching',
        'monotony' => 1.0,
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Interval,
    ]);
    $activity = Activity::factory()->summaryOnly()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->copy()->subDay()->setTime(12, 0),
        'elapsed_time' => 3600,
        'distance' => 10_000,
        'has_heartrate' => true,
        'trimp_edwards' => 130.0,
        'stream_summary' => ['time_in_zone_min' => ['Z2' => 20, 'Z4' => 12]],
    ]);

    $held = collect($this->assembler->weeks($user, Carbon::today()))
        ->firstWhere('type', 'current')['days'][0];

    $activity->update(['ingest_state' => IngestState::Detailed]);
    app()->forgetScopedInstances();

    $resumed = collect(app(PlanPageAssembler::class)->weeks($user, Carbon::today()))
        ->firstWhere('type', 'current')['days'][0];

    expect($held['eased_from'])->toBeNull()
        ->and($resumed['session_type'])->toBe('easy')
        ->and(assemblerShownReadiness($resumed)['reasons'])->toContain('demanding_session_within_24h');
});

it('reports the baseline session count the plan is built on', function (): void {
    expect($this->assembler->sessionsPerWeek(assemblerAthlete(), Carbon::today()))->toBeGreaterThan(0);
});

it('asks for no cooldown when the athlete has not just replanned', function (): void {
    expect($this->assembler->regenerateCooldownSeconds(assemblerAthlete()))->toBeNull();
});

/**
 * A plan generated mid-week only stores rows from that day on, so its week
 * target sums three days while the completed figure was subtracted from the
 * calendar Monday — everything the athlete ran before the plan existed was
 * deducted from a target that never included it. The measured result was an
 * easy day of 1 km and a long run of 1.6.
 */
it('measures the week already run from the first planned day, not the calendar Monday', function (): void {
    Carbon::setTestNow('2026-08-14 08:00:00'); // Friday
    $user = assemblerAthlete();

    foreach ([['2026-08-14', SessionType::Easy], ['2026-08-15', SessionType::Tempo], ['2026-08-16', SessionType::Long]] as [$date, $type]) {
        PlannedSession::factory()->for($user)->create([
            'date' => $date,
            'session_type' => $type,
            'volume_multiplier' => 1.0,
        ]);
    }

    // Monday to Thursday, before the plan existed.
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-08-11 07:00'),
        'distance' => 12_400.0,
    ]);

    $days = collect($this->assembler->weeks($user, Carbon::today()))
        ->firstOrFail(fn (array $week): bool => $week['type'] === 'current')['days'];

    $sunday = collect($days)->firstOrFail(fn (array $day): bool => $day['date'] === '2026-08-16');

    expect($sunday['distance_km'])->toBe($sunday['asked_km']);
});

it('counts a pinned day already run once, not as both pinned and completed volume', function (): void {
    Carbon::setTestNow('2026-08-13 08:00:00'); // Thursday
    $user = assemblerAthlete();

    foreach ([
        ['2026-08-10', SessionType::Rest, false],
        ['2026-08-11', SessionType::Easy, true],
        ['2026-08-12', SessionType::Rest, true],
        ['2026-08-13', SessionType::Tempo, true],
        ['2026-08-14', SessionType::Rest, false],
        ['2026-08-15', SessionType::Easy, false],
        ['2026-08-16', SessionType::Long, false],
    ] as [$date, $type, $pinned]) {
        PlannedSession::factory()->for($user)->create([
            'date' => $date,
            'session_type' => $type,
            'volume_multiplier' => 1.0,
            'pinned' => $pinned,
        ]);
    }

    $dayOn = fn (string $date): array => collect(
        collect($this->assembler->weeks($user, Carbon::today()))
            ->firstOrFail(fn (array $week): bool => $week['type'] === 'current')['days'],
    )->firstOrFail(fn (array $day): bool => $day['date'] === $date);

    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-08-11 06:00'),
        'distance' => $dayOn('2026-08-11')['asked_km'] * 1000,
    ]);

    $saturday = $dayOn('2026-08-15');

    expect($saturday['distance_km'])->toBe($saturday['asked_km']);
});

it('trims only the easy days when the week runs ahead, never the long run', function (): void {
    Carbon::setTestNow('2026-08-13 08:00:00'); // Thursday
    $user = assemblerAthlete();

    foreach ([
        ['2026-08-11', SessionType::Easy],
        ['2026-08-13', SessionType::Rest],
        ['2026-08-15', SessionType::Easy],
        ['2026-08-16', SessionType::Long],
    ] as [$date, $type]) {
        PlannedSession::factory()->for($user)->create([
            'date' => $date,
            'session_type' => $type,
            'volume_multiplier' => 1.0,
        ]);
    }

    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-08-11 06:00'),
        'distance' => 20_000.0,
    ]);

    $days = collect(collect($this->assembler->weeks($user, Carbon::today()))
        ->firstOrFail(fn (array $week): bool => $week['type'] === 'current')['days'])
        ->keyBy('date');

    expect($days['2026-08-16']['distance_km'])->toBe($days['2026-08-16']['asked_km'])
        ->and($days['2026-08-15']['distance_km'])->toBeLessThan($days['2026-08-15']['asked_km']);
});

function freshAssembler(): PlanPageAssembler
{
    app()->forgetScopedInstances();

    return app(PlanPageAssembler::class);
}

function assemblerPastRace(User $user, array $attributes): RaceGoal
{
    return RaceGoal::factory()->for($user)->completed()->create([
        'race_date' => '2026-08-02',
        'distance_m' => 10_000,
        'goal_time_sec' => 3_000,
        'outcome' => RaceOutcome::Pending,
        ...$attributes,
    ]);
}

it('shows no race card with no race at all', function (): void {
    $user = User::factory()->create();

    expect(freshAssembler()->raceCard($user, Carbon::today()))->toBeNull();
});

it('reads a race more than a week out as ahead, with the days to go', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['race_date' => '2026-08-18', 'distance_m' => 10_000, 'goal_time_sec' => 3_600, 'name' => 'City 10K']);

    expect(freshAssembler()->raceCard($user, Carbon::today()))->toBe([
        'state' => 'ahead',
        'race_id' => $race->id,
        'race_date' => '2026-08-18',
        'distance_m' => 10_000,
        'goal_time_sec' => 3_600,
        'name' => 'City 10K',
        'days_to_race' => 8,
        'finish_time_sec' => null,
    ]);
});

it('reads race week from seven days out down to the day before', function (string $raceDate, int $days): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['race_date' => $raceDate]);

    expect(freshAssembler()->raceCard($user, Carbon::today()))
        ->toMatchArray(['state' => 'week', 'days_to_race' => $days]);
})->with([
    'seven days out' => ['2026-08-17', 7],
    'the day before' => ['2026-08-11', 1],
]);

it('reads race day on the race date', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['race_date' => '2026-08-10']);

    expect(freshAssembler()->raceCard($user, Carbon::today()))
        ->toMatchArray(['state' => 'day', 'days_to_race' => 0]);
});

it('turns to race day at midnight on the app clock, not in UTC', function (): void {
    expect(config('app.timezone'))->toBe('Asia/Jakarta');
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['race_date' => '2026-08-10']);

    Carbon::setTestNow(Carbon::parse('2026-08-09 23:59:59', 'Asia/Jakarta'));
    expect(freshAssembler()->raceCard($user, Carbon::today())['state'])->toBe('week');

    Carbon::setTestNow(Carbon::parse('2026-08-09 17:30:00', 'UTC'));
    expect(freshAssembler()->raceCard($user, Carbon::today())['state'])->toBe('day');
});

it('asks about a past race whose result is still pending', function (): void {
    $user = User::factory()->create();
    $race = assemblerPastRace($user, []);

    expect(freshAssembler()->raceCard($user, Carbon::today()))
        ->toMatchArray(['state' => 'result_pending', 'race_id' => $race->id, 'race_date' => '2026-08-02', 'days_to_race' => null]);
});

it('asks about a pending result ahead of the next race, but not on race day', function (): void {
    $user = User::factory()->create();
    assemblerPastRace($user, []);
    $next = RaceGoal::factory()->for($user)->create(['race_date' => '2026-08-13']);

    expect(freshAssembler()->raceCard($user, Carbon::today())['state'])->toBe('result_pending');

    $next->update(['race_date' => '2026-08-10']);
    expect(freshAssembler()->raceCard($user, Carbon::today())['state'])->toBe('day');
});

it('asks only about the most recent past race', function (): void {
    $user = User::factory()->create();
    assemblerPastRace($user, ['race_date' => '2026-07-05']);
    assemblerPastRace($user, ['race_date' => '2026-08-02', 'outcome' => RaceOutcome::DidNotRun]);

    expect(freshAssembler()->raceCard($user, Carbon::today()))->toBeNull();
});

it('shows a confirmed result for seven days after it was recorded, while no race is set', function (string $recordedAt, ?string $state): void {
    $user = User::factory()->create();
    assemblerPastRace($user, ['outcome' => RaceOutcome::Confirmed, 'finish_time_sec' => 2_921, 'outcome_recorded_at' => $recordedAt]);

    expect(freshAssembler()->raceCard($user, Carbon::today())['state'] ?? null)->toBe($state);
})->with([
    'recorded today' => ['2026-08-10 07:00:00', 'result_recorded'],
    'recorded six days ago' => ['2026-08-04 00:00:00', 'result_recorded'],
    'recorded seven days ago' => ['2026-08-03 23:59:59', null],
]);

it('carries the finish time only for a recorded result', function (): void {
    $user = User::factory()->create();
    assemblerPastRace($user, ['outcome' => RaceOutcome::Confirmed, 'finish_time_sec' => 2_921, 'outcome_recorded_at' => '2026-08-09 07:00:00']);

    expect(freshAssembler()->raceCard($user, Carbon::today()))
        ->toMatchArray(['state' => 'result_recorded', 'finish_time_sec' => 2_921, 'goal_time_sec' => 3_000]);
});

it('drops a recorded result once the next race is set', function (): void {
    $user = User::factory()->create();
    assemblerPastRace($user, ['outcome' => RaceOutcome::Confirmed, 'finish_time_sec' => 2_921, 'outcome_recorded_at' => '2026-08-09 07:00:00']);
    RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-04']);

    expect(freshAssembler()->raceCard($user, Carbon::today())['state'])->toBe('ahead');
});

it('shows nothing for a race not run, called off, or confirmed without a time', function (array $attributes): void {
    $user = User::factory()->create();
    assemblerPastRace($user, ['outcome_recorded_at' => '2026-08-09 07:00:00', ...$attributes]);

    expect(freshAssembler()->raceCard($user, Carbon::today()))->toBeNull();
})->with([
    'did not run' => [['outcome' => RaceOutcome::DidNotRun]],
    'cancelled' => [['outcome' => RaceOutcome::Cancelled]],
    'confirmed without a time' => [['outcome' => RaceOutcome::Confirmed, 'finish_time_sec' => null]],
]);

it('ignores a called-off race whose date is still ahead', function (): void {
    $user = User::factory()->create();
    assemblerPastRace($user, ['race_date' => '2026-09-06', 'outcome' => RaceOutcome::Cancelled]);

    expect(freshAssembler()->raceCard($user, Carbon::today()))->toBeNull();
});

it('serves the supported time only for a race ahead', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-04', 'distance_m' => 10_000]);
    seedConfirmedEffort($user, 5_000, 1_320, Carbon::parse('2026-08-01'));

    $supported = freshAssembler()->raceSupportedTimeSec($user, Carbon::today());

    expect($supported)->toBeInt()->toBeGreaterThan(0)
        ->and($supported)->toBe(app(RaceAmbitionAssessor::class)->assess($user, $race)->supportedTimeSec);

    $race->update(['race_date' => '2026-08-14']);
    expect(freshAssembler()->raceSupportedTimeSec($user, Carbon::today()))->toBeNull();
});

it('offers the best race-day run only while a result is pending', function (): void {
    $user = User::factory()->create();
    $race = assemblerPastRace($user, []);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => '2026-08-02 06:30:00', 'distance' => 10_080.0, 'elapsed_time' => 2_921, 'moving_time' => 2_900]);

    expect(freshAssembler()->raceOutcomeCandidate($user, Carbon::today()))
        ->toMatchArray(['activity_id' => $activity->id, 'distance_m' => 10_080.0, 'elapsed_time_sec' => 2_921]);

    $race->update(['outcome' => RaceOutcome::DidNotRun]);
    expect(freshAssembler()->raceOutcomeCandidate($user, Carbon::today()))->toBeNull();
});
