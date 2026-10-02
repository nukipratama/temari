<?php

declare(strict_types=1);

use App\Enums\RaceOutcome;
use App\Enums\SeasonPerformance;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\SeasonGoal;
use App\Models\User;
use App\Services\Gamification\SeasonRecordBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-20 08:00:00');
    $this->builder = app(SeasonRecordBuilder::class);
    $this->user = User::factory()->create();
});
afterEach(fn () => Carbon::setTestNow());

function raceSeason(User $user, ?RaceOutcome $outcome, ?int $finishTimeSec = null): Season
{
    $race = RaceGoal::factory()->for($user)->completed()->create([
        'race_date' => '2026-10-18', 'distance_m' => 10_000, 'goal_time_sec' => 3_000,
        'outcome' => $outcome, 'finish_time_sec' => $finishTimeSec,
    ]);
    $season = Season::factory()->for($user)->create(['race_goal_id' => $race->id, 'starts_at' => '2026-10-05', 'ends_at' => '2026-10-18']);
    SeasonGoal::factory()->for($season)->create(['metric' => 'season_sessions_completed', 'title' => 'sessions', 'target' => 2, 'unit' => 'sessions']);
    SeasonGoal::factory()->for($season)->create(['metric' => 'season_race_goal_met', 'title' => 'race', 'target' => 1, 'unit' => 'race']);
    foreach (['2026-10-06', '2026-10-08'] as $date) {
        PlannedSession::factory()->for($user)->create(['date' => $date, 'session_type' => SessionType::Easy]);
        ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create(['start_date_local' => $date.' 07:00:00', 'distance' => 5_000]);
    }

    return $season->load('goals', 'raceGoal');
}

it('scores process from the training goals alone, so a missed time goal cannot erase progress', function (): void {
    $record = $this->builder->build($this->user, raceSeason($this->user, RaceOutcome::Confirmed, 3_600), Carbon::today());

    expect($record['process']['pct'])->toBe(100)
        ->and($record['process']['goals_met'])->toBe(1)
        ->and($record['process']['goals_total'])->toBe(1)
        ->and($record['performance'])->toBe([
            'state' => 'not_met', 'target_time_sec' => 3_000, 'finish_time_sec' => 3_600, 'margin_pct' => 20.0,
        ]);
});

it('reports a finish within the margin as met', function (): void {
    $record = $this->builder->build($this->user, raceSeason($this->user, RaceOutcome::Confirmed, 3_100), Carbon::today());

    expect($record['performance']['state'])->toBe('met')
        ->and($record['performance']['margin_pct'])->toBe(3.3);
});

it('reports pending, did not run and cancelled without any result or miss', function (RaceOutcome $outcome, string $state): void {
    $record = $this->builder->build($this->user, raceSeason($this->user, $outcome), Carbon::today());

    expect($record['performance']['state'])->toBe($state)
        ->and($record['performance']['finish_time_sec'])->toBeNull()
        ->and($record['performance']['margin_pct'])->toBeNull()
        ->and($record['process']['pct'])->toBe(100);
})->with([
    'pending' => [RaceOutcome::Pending, 'pending'],
    'did not run' => [RaceOutcome::DidNotRun, 'did_not_run'],
    'cancelled' => [RaceOutcome::Cancelled, 'cancelled'],
]);

it('does not claim an outcome for a legacy race without one', function (): void {
    expect($this->builder->build($this->user, raceSeason($this->user, null), Carbon::today())['performance']['state'])->toBe('unrecorded');
});

it('reports no performance for a season without a race', function (): void {
    $season = Season::factory()->for($this->user)->create(['starts_at' => '2026-10-05', 'ends_at' => '2026-12-28']);

    expect($this->builder->build($this->user, $season, Carbon::today()))->toMatchArray([
        'process' => ['pct' => null, 'goals_met' => 0, 'goals_total' => 0],
        'performance' => ['state' => 'none', 'target_time_sec' => null, 'finish_time_sec' => null, 'margin_pct' => null],
    ]);
});

it('stores the record when a season is settled, and the same call again changes nothing', function (): void {
    $season = raceSeason($this->user, RaceOutcome::Pending);

    $this->builder->settle($this->user, $season, Carbon::today());
    $first = $season->fresh();
    $this->builder->settle($this->user, $season->fresh(), Carbon::today());

    expect($first->process_pct)->toBe(100)
        ->and($first->performance_state)->toBe(SeasonPerformance::Pending)
        ->and($first->record_settled_at)->not->toBeNull()
        ->and($season->fresh()->performance_state)->toBe(SeasonPerformance::Pending);
});

it('updates only the performance of settled seasons when the race is confirmed late', function (): void {
    $season = raceSeason($this->user, RaceOutcome::Pending);
    $this->builder->settle($this->user, $season, Carbon::today());
    $race = $season->raceGoal;
    $race->update(['outcome' => RaceOutcome::Confirmed, 'finish_time_sec' => 3_050]);

    $this->builder->settleForRace($race->fresh());

    expect($season->fresh()->performance_state)->toBe(SeasonPerformance::Met)
        ->and($season->fresh()->process_pct)->toBe(100);
});

it('leaves a season that was never settled for its own close to settle', function (): void {
    $season = raceSeason($this->user, RaceOutcome::Pending);

    $this->builder->settleForRace($season->raceGoal);

    expect($season->fresh()->record_settled_at)->toBeNull()
        ->and($season->fresh()->performance_state)->toBeNull();
});
