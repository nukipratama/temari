<?php

declare(strict_types=1);

use App\Enums\ExperienceLevel;
use App\Enums\FallOffTilt;
use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\PersonalRecord;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\PlanInputsGatherer;
use App\Services\Run\Plan\RaceAmbitionAssessor;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-07 08:00:00');
    $this->gatherer = app(PlanInputsGatherer::class);
});
afterEach(fn () => Carbon::setTestNow());

it('budgets actual marathon-band minutes without escalating threshold readiness stress', function (): void {
    $user = gathererAthlete();
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
        'start_date_local' => '2026-09-06 07:00', 'elapsed_time' => 3600,
        'stream_summary' => ['time_in_zone_min' => ['Z1' => 0, 'Z2' => 30, 'Z3' => 30, 'Z4' => 0, 'Z5' => 0]],
    ]);
    expect($this->gatherer->forUser($user, Carbon::today())->actualSessions)->toBe([
        ['date' => '2026-09-06', 'duration_minutes' => 60, 'hard_minutes' => 30.0, 'demanding' => true],
    ]);
});

it('requires six recent consistent running weeks and credible paces for two-run quality', function (): void {
    $user = User::factory()->create();
    $weeks = collect(range(0, 5))->map(fn (int $offset) => WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->subDay()->subWeeks($offset), 'runs' => 2, 'distance_km' => 12.0,
    ]));
    expect($this->gatherer->forUser($user, Carbon::today())->twoRunQualityEligible)->toBeFalse();
    PersonalRecord::factory()->for($user)->create(['category' => '10km', 'value_sec' => 3000, 'set_at' => Carbon::today()]);
    seedConfirmedEffort($user, 10_000, 3000, Carbon::today());
    app(VdotEstimator::class)->forget($user);
    expect($this->gatherer->forUser($user, Carbon::today())->twoRunQualityEligible)->toBeTrue();
    $weeks->last()->update(['runs' => 1]);
    expect($this->gatherer->forUser($user, Carbon::today())->twoRunQualityEligible)->toBeFalse();
});

it('removes skipped prescriptions from workload and restores them only after unskipping', function (): void {
    $user = gathererAthlete();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => '2026-09-09', 'session_type' => SessionType::Tempo,
        'skipped' => true, 'prescribed_hard_minutes' => 20, 'prescribed_pace_band' => PaceBand::Threshold,
    ]);
    expect($this->gatherer->forUser($user, Carbon::today())->fixedSessions)->not->toHaveKey('2026-09-09');
    $session->update(['skipped' => false, 'pinned' => true]);
    expect($this->gatherer->forUser($user, Carbon::today())->fixedSessions)->toHaveKey('2026-09-09');
});

function gathererAthlete(): User
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

it('opens a season for an athlete who does not have one yet', function (): void {
    $user = gathererAthlete();

    $inputs = $this->gatherer->forUser($user, Carbon::today());

    expect(Season::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($inputs->seasonStart->lte(Carbon::today()))->toBeTrue()
        ->and($inputs->userId)->toBe($user->id);
});

it('reads the active race and what it projects to take this athlete', function (): void {
    $user = gathererAthlete();
    RaceGoal::factory()->for($user)->create([
        'race_date' => '2026-10-31',
        'distance_m' => 10_000,
        'goal_time_sec' => 3_540,
    ]);

    $inputs = $this->gatherer->forUser($user, Carbon::today());

    expect($inputs->raceDate?->toDateString())->toBe('2026-10-31')
        ->and($inputs->raceDistanceM)->toBe(10_000.0)
        ->and($inputs->isSelfScaled())->toBeFalse();
});

it('leaves the plan self-scaled when no race is set', function (): void {
    $inputs = $this->gatherer->forUser(gathererAthlete(), Carbon::today());

    expect($inputs->raceDate)->toBeNull()
        ->and($inputs->raceDistanceM)->toBeNull()
        ->and($inputs->projectedRaceSeconds)->toBeNull()
        ->and($inputs->isSelfScaled())->toBeTrue();
});

it('carries the weekdays the athlete chose to run on', function (): void {
    $user = gathererAthlete();
    TrainingPreference::query()->create([
        'user_id' => $user->id,
        'experience_level' => ExperienceLevel::Experienced,
        'sessions_per_week' => 4,
        'run_days' => [1, 3, 5, 6],
        'long_run_day' => 6,
    ]);

    $inputs = $this->gatherer->forUser($user, Carbon::today());

    expect($inputs->runDays)->toBe([1, 3, 5, 6])
        ->and($inputs->longRunDay)->toBe(6)
        ->and($inputs->sessionsPerWeek)->toBe(4);
});

it('collects fixed session prescriptions across the horizon without making past dates writable', function (): void {
    Carbon::setTestNow('2026-09-09 08:00:00');
    $user = gathererAthlete();
    $yesterday = Carbon::today()->subDay()->toDateString();
    $tomorrow = Carbon::today()->addDay()->toDateString();
    $inTwoDays = Carbon::today()->addDays(2)->toDateString();
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();
    $nextWeek = Carbon::today()->startOfWeek(Carbon::MONDAY)->addWeek()->addDay()->toDateString();

    PlannedSession::factory()->for($user)->create([
        'date' => $monday,
        'session_type' => SessionType::Tempo,
        'prescribed_hard_minutes' => 20,
        'prescribed_pace_band' => PaceBand::Threshold,
    ]);
    PlannedSession::factory()->for($user)->pinned()->create([
        'date' => $yesterday,
        'session_type' => SessionType::Interval,
        'prescribed_hard_minutes' => 18,
        'prescribed_pace_band' => PaceBand::Interval,
    ]);
    PlannedSession::factory()->for($user)->create(['date' => $tomorrow, 'pinned' => true]);
    PlannedSession::factory()->for($user)->create(['date' => $inTwoDays, 'status' => PlannedSessionStatus::Done]);
    PlannedSession::factory()->for($user)->pinned()->create([
        'date' => $nextWeek,
        'session_type' => SessionType::Interval,
        'prescribed_hard_minutes' => 18,
        'prescribed_pace_band' => PaceBand::Interval,
    ]);

    $inputs = $this->gatherer->forUser($user, Carbon::today());

    expect(array_keys($inputs->pinnedDates))->toBe([$tomorrow, $nextWeek])
        ->and(array_keys($inputs->settledDates))->toBe([$inTwoDays])
        ->and($inputs->fixedSessions)->toBe([
            $tomorrow => [
                'session_type' => SessionType::Easy,
                'prescribed_hard_minutes' => 0,
                'prescribed_pace_band' => null,
            ],
            $inTwoDays => [
                'session_type' => SessionType::Easy,
                'prescribed_hard_minutes' => 0,
                'prescribed_pace_band' => null,
            ],
            $nextWeek => [
                'session_type' => SessionType::Interval,
                'prescribed_hard_minutes' => 18,
                'prescribed_pace_band' => PaceBand::Interval,
            ],
        ]);
});

it('states what the adapter decided about the week being planned', function (): void {
    $inputs = $this->gatherer->forUser(gathererAthlete(), Carbon::today());

    expect($inputs->adaptation)->toHaveKeys(['reason', 'deload', 'quality_delta', 'adherence_pct', 'stimulus_adherence_pct']);
});

it('uses each historical row race context when finding comparable hard work', function (): void {
    $user = gathererAthlete();
    RaceGoal::factory()->for($user)->create([
        'race_date' => '2026-10-31',
        'distance_m' => 42_195,
        'goal_time_sec' => 14_400,
    ]);

    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->subDay(),
        'session_type' => 'tempo',
        'status' => PlannedSessionStatus::Done,
        'intent_verdict' => IntentVerdict::Hit,
        'intent_evidence' => ['advice_history' => 'shown', 'quality_progression' => 'eligible'],
        'prescribed_hard_minutes' => 20,
        'prescription_race_context' => null,
    ]);

    $inputs = $this->gatherer->forUser($user, Carbon::today());

    expect($inputs->recentPrescriptions)->toHaveKey('tempo')
        ->and($inputs->recentPrescriptions)->not->toHaveKey('race_tempo');
});

it('does not progress hard minutes from a hit without shown-advice history', function (): void {
    $user = gathererAthlete();

    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->subDay(),
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Done,
        'intent_verdict' => IntentVerdict::Hit,
        'intent_evidence' => ['advice_history' => 'unknown'],
        'prescribed_hard_minutes' => 20,
    ]);

    $inputs = $this->gatherer->forUser($user, Carbon::today());

    expect($inputs->recentPrescriptions)->not->toHaveKey('tempo');
});

it('ignores a newer unknown-history overreach and keeps the latest shown quality grade', function (): void {
    $user = gathererAthlete();

    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->subDays(2),
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Done,
        'intent_verdict' => IntentVerdict::Hit,
        'intent_evidence' => ['advice_history' => 'shown', 'quality_progression' => 'eligible'],
        'prescribed_hard_minutes' => 20,
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->subDay(),
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Overreached,
        'intent_verdict' => IntentVerdict::TooHard,
        'intent_evidence' => ['advice_history' => 'unknown'],
        'prescribed_hard_minutes' => 26,
    ]);

    $inputs = $this->gatherer->forUser($user, Carbon::today());

    expect($inputs->recentPrescriptions['tempo'])->toBe([
        'verdict' => IntentVerdict::Hit,
        'hard_minutes' => 20,
    ]);
});

it('does not progress the abandoned tempo dose from an eased tempo completed easy', function (): void {
    $user = gathererAthlete();

    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->subDay(),
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Done,
        'clamped_km' => 6.4,
        'intent_verdict' => IntentVerdict::Hit,
        'intent_evidence' => ['advice_history' => 'shown', 'effective_type' => 'easy', 'eased_from' => 'tempo', 'concern' => 'mild', 'stimulus_family' => 'easy'],
        'prescribed_hard_minutes' => 20,
    ]);

    expect($this->gatherer->forUser($user, Carbon::today())->recentPrescriptions)->not->toHaveKey('tempo');
});

it('budgets a settled eased tempo as the easy day it became, without the abandoned tempo minutes', function (): void {
    $user = gathererAthlete();
    $yesterday = Carbon::today()->subDay()->toDateString();

    PlannedSession::factory()->for($user)->create([
        'date' => $yesterday,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Done,
        'clamped_km' => 6.4,
        'prescribed_hard_minutes' => 20,
        'prescribed_pace_band' => PaceBand::Threshold,
        'intent_verdict' => IntentVerdict::Hit,
        'intent_evidence' => ['advice_history' => 'shown', 'effective_type' => 'easy', 'eased_from' => 'tempo', 'concern' => 'mild', 'stimulus_family' => 'easy'],
    ]);

    expect($this->gatherer->forUser($user, Carbon::today())->fixedSessions[$yesterday])->toBe([
        'session_type' => SessionType::Easy,
        'prescribed_hard_minutes' => 0,
        'prescribed_pace_band' => null,
    ]);
});

it('budgets a settled eased tempo with no shown-advice evidence by its recorded clamp', function (): void {
    $user = gathererAthlete();
    $yesterday = Carbon::today()->subDay()->toDateString();

    PlannedSession::factory()->for($user)->create([
        'date' => $yesterday,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Done,
        'clamped_km' => 6.4,
        'prescribed_hard_minutes' => 20,
        'prescribed_pace_band' => PaceBand::Threshold,
    ]);

    expect($this->gatherer->forUser($user, Carbon::today())->fixedSessions[$yesterday]['session_type'])->toBe(SessionType::Easy);
});

it('counts self-added hard work on a settled easy day as demanding, with its measured minutes', function (): void {
    $user = gathererAthlete();
    $yesterday = Carbon::today()->subDay()->toDateString();

    PlannedSession::factory()->for($user)->create([
        'date' => $yesterday,
        'session_type' => SessionType::Easy,
        'status' => PlannedSessionStatus::Overreached,
        'intent_verdict' => IntentVerdict::TooHard,
        'intent_evidence' => ['advice_history' => 'shown', 'effective_type' => 'easy', 'concern' => 'none', 'stimulus_family' => 'hard', 'stimulus_minutes' => 23.0, 'stimulus_source' => 'heart_rate'],
    ]);

    expect($this->gatherer->forUser($user, Carbon::today())->fixedSessions[$yesterday])->toBe([
        'session_type' => SessionType::Easy,
        'prescribed_hard_minutes' => 0,
        'prescribed_pace_band' => null,
        'hard_minutes' => 23.0,
        'demanding' => true,
    ]);
});

it('counts a controlled original tempo completed against eased advice as a demanding day', function (): void {
    $user = gathererAthlete();
    $yesterday = Carbon::today()->subDay()->toDateString();

    PlannedSession::factory()->for($user)->create([
        'date' => $yesterday,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Overreached,
        'clamped_km' => 6.4,
        'prescribed_hard_minutes' => 20,
        'prescribed_pace_band' => PaceBand::Threshold,
        'intent_verdict' => IntentVerdict::TooHard,
        'intent_evidence' => ['advice_history' => 'shown', 'effective_type' => 'easy', 'eased_from' => 'tempo', 'concern' => 'mild', 'original_completed' => 'controlled', 'stimulus_family' => 'tempo', 'stimulus_minutes' => 20.0, 'stimulus_source' => 'window'],
    ]);

    expect($this->gatherer->forUser($user, Carbon::today())->fixedSessions[$yesterday])->toMatchArray([
        'session_type' => SessionType::Easy,
        'prescribed_hard_minutes' => 0,
        'hard_minutes' => 20.0,
        'demanding' => true,
    ]);
});

it('sizes the race by the time the plan trains for, not a separate Riegel projection', function (): void {
    $user = gathererAthlete();
    RaceGoal::factory()->for($user)->create([
        'race_date' => '2026-10-31',
        'distance_m' => 10_000,
        'goal_time_sec' => 3_540,
    ]);

    $inputs = $this->gatherer->forUser($user, Carbon::today());

    expect($inputs->projectedRaceSeconds)->toBe((float) $inputs->raceGoalTimeSec);
});

it('tilts the plan only by a fitted fall-off, and only outside the neutral band', function (?int $fifteenKSec, ?FallOffTilt $expected): void {
    $user = User::factory()->create();
    seedConfirmedEffort($user, 5_000, 1_500, Carbon::today()->subWeeks(3));
    if ($fifteenKSec !== null) {
        seedConfirmedEffort($user, 15_000, $fifteenKSec, Carbon::today()->subWeeks(2));
    }

    expect($this->gatherer->forUser($user, Carbon::today())->fallOffTilt)->toBe($expected);
})->with([
    'slow fall-off' => [(int) round(1_500 * 3 ** 1.13), FallOffTilt::Endurance],
    'flat fall-off' => [(int) round(1_500 * 3 ** 1.03), FallOffTilt::Speed],
    'neutral fall-off' => [(int) round(1_500 * 3 ** 1.08), null],
    'no fitted fall-off' => [null, null],
]);

it('carries the race ambition band and gap the goal-pace work is gated on, and none without a race', function (): void {
    $user = gathererAthlete();
    $withoutRace = $this->gatherer->forUser($user, Carbon::today());
    $race = RaceGoal::factory()->for($user)->create([
        'race_date' => '2026-10-31',
        'distance_m' => 10_000,
        'goal_time_sec' => 3_540,
    ]);
    $inputs = $this->gatherer->forUser($user, Carbon::today());
    $ambition = app(RaceAmbitionAssessor::class)->assess($user, $race, Carbon::today());

    expect($withoutRace->raceAmbitionState)->toBeNull()
        ->and($withoutRace->raceAmbitionGapPct)->toBeNull()
        ->and($inputs->raceAmbitionState)->toBe($ambition->state)
        ->and($inputs->raceAmbitionGapPct)->toBe($ambition->gapPct);
});

it('carries the trial aim, the season\'s fixed trials read as run or skipped, and recent evidence at the trial distance', function (): void {
    $user = gathererAthlete();
    Season::factory()->for($user)->create(['starts_at' => '2026-08-03', 'ends_at' => '2026-10-25']);
    seedConfirmedEffort($user, 5_000, 1_500, Carbon::parse('2026-08-20'));
    seedConfirmedEffort($user, 10_000, 3_200, Carbon::parse('2026-08-25'));
    seedConfirmedEffort($user, 5_000, 1_520, Carbon::parse('2026-06-01'));
    $trial = ['kind' => 'time_trial', 'distance_m' => 5_000, 'aim_time_sec' => 1_500, 'retry' => 0];
    foreach (['2026-08-18' => [], '2026-08-25' => [], '2026-09-01' => ['skipped' => true], '2026-09-10' => ['pinned' => true]] as $date => $attributes) {
        PlannedSession::factory()->for($user)->create([
            'date' => $date,
            'session_type' => SessionType::Interval,
            'prescribed_hard_minutes' => 25,
            'prescribed_pace_band' => PaceBand::Interval,
            'prescription_race_context' => $date === '2026-08-25' ? [...$trial, 'retry' => 1] : $trial,
            ...$attributes,
        ]);
    }
    PlannedSession::factory()->for($user)->create(['date' => '2026-09-08', 'session_type' => SessionType::Interval, 'prescription_race_context' => $trial]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create(['start_date_local' => '2026-08-25 06:30:00', 'distance' => 5_000.0]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
        'start_date_local' => '2026-08-28 06:00:00', 'distance' => 5_100.0, 'elapsed_time' => 1_480, 'moving_time' => 1_480,
        'stream_summary' => ['per_km' => array_map(static fn (int $km): array => ['km' => $km, 'pace' => '4:50', 'elapsed_sec' => 290, 'distance_m' => 1000], range(1, 5))],
    ]);
    $estimate = app(VdotEstimator::class)->estimate($user, Carbon::today());

    $inputs = $this->gatherer->forUser($user, Carbon::today());

    expect($inputs->timeTrialAimSec)->toBe((int) round(app(VdotEstimator::class)->raceTimeForVdot($estimate['vdot'], 5_000)))
        ->and($inputs->timeTrials)->toBe([
            ['date' => '2026-08-18', 'retry' => false, 'skipped' => true],
            ['date' => '2026-08-25', 'retry' => true, 'skipped' => false],
            ['date' => '2026-09-01', 'retry' => false, 'skipped' => true],
            ['date' => '2026-09-10', 'retry' => false, 'skipped' => false],
        ])
        ->and($inputs->timeTrialEvidenceDates)->toBe(['2026-08-20', '2026-08-28']);
});
