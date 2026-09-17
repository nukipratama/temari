<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\PlannedSession;
use App\Models\Season;
use App\Models\User;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\ComplianceScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** An easy day graded before intent counted, run well under marathon pace at the full distance. */
function regradeFastEasyDay(User $user, string $date, PlannedSessionStatus $status): PlannedSession
{
    $row = PlannedSession::factory()->for($user)->create([
        'date' => $date,
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Easy,
        'status' => $status,
        'compliance_score' => $status === PlannedSessionStatus::Planned ? null : 100,
    ]);

    $askedKm = (float) app(ComplianceScorer::class)->verdictsFor($user, PlannedSession::query()->whereKey($row->id)->get(), Carbon::today())[$date]['prescribed_km'];
    $paces = app(TrainingPaceCalculator::class)->fromVdotResult(app(VdotEstimator::class)->estimate($user, Carbon::parse($date)));
    $seconds = (int) round($askedKm * (($paces['marathon'] ?? 300) - 30));

    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::parse($date.' 06:00:00'),
        'distance' => $askedKm * 1000,
        'moving_time' => $seconds,
        'elapsed_time' => $seconds,
    ]);

    return $row;
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-20 09:00:00');
    $this->user = User::factory()->create();
    PersonalRecord::factory()->for($this->user)->create(['category' => '5km', 'value_sec' => 1500, 'set_at' => '2026-07-01']);
    Season::factory()->for($this->user)->create(['starts_at' => '2026-08-03', 'ends_at' => '2026-10-25']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('regrades the current season\'s past days on distance and intent', function (): void {
    $row = regradeFastEasyDay($this->user, '2026-08-05', PlannedSessionStatus::Done);

    $this->artisan('plan:regrade-season')
        ->expectsOutputToContain('Regraded 1 planned session(s) across 1 season(s).')
        ->assertSuccessful();

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Overreached)
        ->and($row->compliance_score)->toBe(100)
        ->and($row->distance_score)->toBe(100);
});

it('changes nothing the second time it runs', function (): void {
    $row = regradeFastEasyDay($this->user, '2026-08-05', PlannedSessionStatus::Done);
    $this->artisan('plan:regrade-season')->assertSuccessful();
    $first = $row->refresh()->only(['status', 'compliance_score', 'distance_score', 'prescribed_km', 'ran_anyway', 'updated_at']);

    Carbon::setTestNow('2026-08-20 10:00:00');
    $this->artisan('plan:regrade-season')
        ->expectsOutputToContain('Regraded 0 planned session(s) across 1 season(s).')
        ->assertSuccessful();

    expect($row->refresh()->only(['status', 'compliance_score', 'distance_score', 'prescribed_km', 'ran_anyway', 'updated_at']))->toEqual($first);
});

it('leaves today and the days before the season alone', function (): void {
    $before = regradeFastEasyDay($this->user, '2026-07-29', PlannedSessionStatus::Done);
    $today = regradeFastEasyDay($this->user, '2026-08-20', PlannedSessionStatus::Planned);

    $this->artisan('plan:regrade-season')->assertSuccessful();

    expect($before->refresh()->status)->toBe(PlannedSessionStatus::Done)
        ->and($before->distance_score)->toBeNull()
        ->and($today->refresh()->status)->toBe(PlannedSessionStatus::Planned);
});

it('limits the pass to one athlete when asked', function (): void {
    $other = User::factory()->create();
    Season::factory()->for($other)->create(['starts_at' => '2026-08-03', 'ends_at' => '2026-10-25']);
    $row = regradeFastEasyDay($this->user, '2026-08-05', PlannedSessionStatus::Done);

    $this->artisan('plan:regrade-season', ['--user' => $other->id])
        ->expectsOutputToContain('Regraded 0 planned session(s) across 1 season(s).')
        ->assertSuccessful();

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Done);
});
