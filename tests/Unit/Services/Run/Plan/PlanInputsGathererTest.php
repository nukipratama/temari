<?php

declare(strict_types=1);

use App\Enums\ExperienceLevel;
use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\PlanInputsGatherer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-07 08:00:00');
    $this->gatherer = app(PlanInputsGatherer::class);
});
afterEach(fn () => Carbon::setTestNow());

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

it('collects fixed current-week prescriptions without making past dates writable', function (): void {
    Carbon::setTestNow('2026-09-09 08:00:00');
    $user = gathererAthlete();
    $yesterday = Carbon::today()->subDay()->toDateString();
    $tomorrow = Carbon::today()->addDay()->toDateString();
    $inTwoDays = Carbon::today()->addDays(2)->toDateString();
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();

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

    $inputs = $this->gatherer->forUser($user, Carbon::today());

    expect(array_keys($inputs->pinnedDates))->toBe([$tomorrow])
        ->and(array_keys($inputs->settledDates))->toBe([$inTwoDays])
        ->and($inputs->fixedSessions)->toBe([
            $monday => [
                'session_type' => SessionType::Tempo,
                'prescribed_hard_minutes' => 20,
                'prescribed_pace_band' => PaceBand::Threshold,
            ],
            $yesterday => [
                'session_type' => SessionType::Interval,
                'prescribed_hard_minutes' => 18,
                'prescribed_pace_band' => PaceBand::Interval,
            ],
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
        'prescribed_hard_minutes' => 20,
        'prescription_race_context' => null,
    ]);

    $inputs = $this->gatherer->forUser($user, Carbon::today());

    expect($inputs->recentPrescriptions)->toHaveKey('tempo')
        ->and($inputs->recentPrescriptions)->not->toHaveKey('race_tempo');
});
