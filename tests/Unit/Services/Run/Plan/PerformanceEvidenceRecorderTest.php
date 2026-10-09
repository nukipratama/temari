<?php

declare(strict_types=1);

use App\Enums\PlannedSessionStatus;
use App\Models\Activity;
use App\Models\PerformanceEvidence;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Plan\PerformanceEvidenceRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 08:00:00');
    $this->recorder = app(PerformanceEvidenceRecorder::class);
    $this->user = User::factory()->create();
});
afterEach(fn () => Carbon::setTestNow());

it('records a plausible result as confirmed evidence', function (): void {
    $recorded = $this->recorder->record($this->user, ['kind' => 'race', 'distance_m' => 10_000, 'elapsed_time_sec' => 3_000, 'performed_on' => '2026-10-04']);

    expect($recorded['evidence']->user_id)->toBe($this->user->id)
        ->and($recorded['evidence']->wasRecentlyCreated)->toBeTrue()
        ->and($recorded['fitness']['vdot_source']['confidence'])->toBe('confirmed');
});

it('rejects an implausible time before anything is stored', function (): void {
    expect(fn () => $this->recorder->record($this->user, ['kind' => 'race', 'distance_m' => 10_000, 'elapsed_time_sec' => 600, 'performed_on' => '2026-10-04']))
        ->toThrow(ValidationException::class);
    expect(PerformanceEvidence::query()->count())->toBe(0);
});

it('qualifies only distances inside the evidence range', function (): void {
    expect(PerformanceEvidenceRecorder::qualifies(999.0))->toBeFalse()
        ->and(PerformanceEvidenceRecorder::qualifies(1_000.0))->toBeTrue()
        ->and(PerformanceEvidenceRecorder::qualifies(42_195.0))->toBeTrue()
        ->and(PerformanceEvidenceRecorder::qualifies(50_000.0))->toBeFalse();
});

it('retracts only the evidence tied to the race', function (): void {
    $race = RaceGoal::factory()->for($this->user)->create();
    $other = RaceGoal::factory()->for($this->user)->completed()->create();
    $this->recorder->record($this->user, ['kind' => 'race', 'distance_m' => 10_000, 'elapsed_time_sec' => 3_000, 'performed_on' => '2026-10-04', 'race_goal_id' => $race->id]);
    $this->recorder->record($this->user, ['kind' => 'race', 'distance_m' => 5_000, 'elapsed_time_sec' => 1_500, 'performed_on' => '2026-09-04', 'race_goal_id' => $other->id]);

    $this->recorder->retractForRace($this->user, $race);

    expect(PerformanceEvidence::query()->pluck('race_goal_id')->all())->toBe([$other->id]);
});

it('does nothing when the race has no evidence to retract', function (): void {
    $race = RaceGoal::factory()->for($this->user)->create();

    $this->recorder->retractForRace($this->user, $race);

    expect(PerformanceEvidence::query()->count())->toBe(0);
});

it('regenerates the plan when a retraction moves the training paces', function (): void {
    $run = Activity::factory()->for($this->user)->create();
    $this->recorder->record($this->user, ['kind' => 'test', 'distance_m' => 5_000, 'elapsed_time_sec' => 1_200, 'performed_on' => '2026-10-04', 'activity_id' => $run->id]);
    $marker = PlannedSession::factory()->for($this->user)->create(['date' => '2026-10-07', 'status' => PlannedSessionStatus::Planned]);

    $this->recorder->retracting($this->user, fn () => PerformanceEvidence::query()->where('activity_id', $run->id)->delete());

    expect(PerformanceEvidence::query()->count())->toBe(0)
        ->and(PlannedSession::query()->whereKey($marker->id)->exists())->toBeFalse();
});

it('leaves the plan alone when a retraction does not move the training paces', function (): void {
    $marker = PlannedSession::factory()->for($this->user)->create(['date' => '2026-10-07', 'status' => PlannedSessionStatus::Planned]);

    $this->recorder->retracting($this->user, fn () => null);

    expect(PlannedSession::query()->whereKey($marker->id)->exists())->toBeTrue();
});
