<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Enums\PerformanceEvidenceKind;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Enums\TimeTrialOutcome;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PerformanceEvidence;
use App\Models\PlannedSession;
use App\Models\User;
use App\Notifications\TimeTrialNotification;
use App\Services\Run\Plan\TimeTrial;
use App\Services\Run\Plan\TimeTrialService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-07 09:05:00');
    Notification::fake();
    $this->trials = app(TimeTrialService::class);
    $this->user = User::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $attributes */
function trialDay(User $user, array $attributes = []): PlannedSession
{
    return PlannedSession::factory()->for($user)->create([
        'date' => '2026-10-06',
        'session_type' => SessionType::Interval,
        'prescribed_hard_minutes' => 25,
        'prescribed_pace_band' => PaceBand::Interval,
        'prescribed_pace_sec_per_km' => 300,
        'prescription_race_context' => new TimeTrial(5_000, 1_500)->context(),
        'status' => PlannedSessionStatus::Done,
        ...$attributes,
    ]);
}

function trialRun(User $user, float $meters, int $seconds, ?float $heartRate = null, string $at = '2026-10-06 06:30:00'): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $at,
        'distance' => $meters,
        'elapsed_time' => $seconds,
        'moving_time' => $seconds,
        'average_heartrate' => $heartRate,
        'has_heartrate' => $heartRate !== null,
    ]);

    return $activity;
}

it('counts a run that clears the gate as test evidence with no question asked', function (float $meters, int $seconds, ?float $heartRate): void {
    $session = trialDay($this->user);
    $run = trialRun($this->user, $meters, $seconds, $heartRate);

    expect($this->trials->settle($session))->toBe(TimeTrialOutcome::Confirmed)
        ->and($session->fresh()->time_trial_outcome)->toBe(TimeTrialOutcome::Confirmed)
        ->and(PerformanceEvidence::query()->sole()->only(['kind', 'distance_m', 'elapsed_time_sec', 'activity_id']))->toBe([
            'kind' => PerformanceEvidenceKind::Test,
            'distance_m' => (int) $meters,
            'elapsed_time_sec' => $seconds,
            'activity_id' => $run->id,
        ]);
    Notification::assertNothingSent();
})->with([
    'on pace' => [5_020.0, 1_560, null],
    'in zone 4' => [5_020.0, 1_800, 172.0],
]);

it('asks once about a run on the day that misses the gate', function (float $meters, int $seconds): void {
    $session = trialDay($this->user);
    trialRun($this->user, $meters, $seconds, 150.0);

    expect($this->trials->settle($session))->toBe(TimeTrialOutcome::Asked)
        ->and($this->trials->settle($session->fresh()))->toBeNull()
        ->and(PerformanceEvidence::query()->count())->toBe(0);
    Notification::assertSentToTimes($this->user, TimeTrialNotification::class, 1);
})->with([
    'too slow' => [5_000.0, 1_800],
    'the wrong distance' => [8_000.0, 2_400],
]);

it('leaves a trial that was not run, excused or eased to the plan\'s retry', function (array $attributes, bool $ran): void {
    $session = trialDay($this->user, $attributes);
    if ($ran) {
        trialRun($this->user, 5_000.0, 1_500);
    }

    expect($this->trials->settle($session))->toBeNull()
        ->and($session->fresh()->time_trial_outcome)->toBeNull();
    Notification::assertNothingSent();
})->with([
    'nothing run' => [[], false],
    'excused' => [['skipped' => true], true],
    'eased to easy' => [['clamped_km' => 6.0], true],
]);

it('ignores a day that is no longer a trial', function (): void {
    $session = trialDay($this->user, ['prescription_race_context' => null]);
    trialRun($this->user, 5_000.0, 1_500);

    expect($this->trials->settle($session))->toBeNull();
});

it('asks rather than counting an implausible run that passed on heart rate', function (): void {
    $session = trialDay($this->user);
    trialRun($this->user, 5_000.0, 7_000, 180.0);

    expect($this->trials->settle($session))->toBe(TimeTrialOutcome::Asked)
        ->and(PerformanceEvidence::query()->count())->toBe(0);
});

it('counts the asked run when the athlete says it was all-out', function (): void {
    $session = trialDay($this->user, ['time_trial_outcome' => TimeTrialOutcome::Asked]);
    trialRun($this->user, 2_000.0, 600, at: '2026-10-06 06:00:00');
    $run = trialRun($this->user, 5_000.0, 1_700);

    expect($this->trials->answer($this->user, $session, true))->toBe(TimeTrialOutcome::Confirmed)
        ->and(PerformanceEvidence::query()->sole()->activity_id)->toBe($run->id)
        ->and($session->fresh()->time_trial_outcome)->toBe(TimeTrialOutcome::Confirmed);
});

it('closes the trial with nothing counted when the athlete says it was not all-out', function (): void {
    $session = trialDay($this->user, ['time_trial_outcome' => TimeTrialOutcome::Asked]);
    trialRun($this->user, 5_000.0, 1_700);

    expect($this->trials->answer($this->user, $session, false))->toBe(TimeTrialOutcome::Declined)
        ->and(PerformanceEvidence::query()->count())->toBe(0)
        ->and($session->fresh()->time_trial_outcome)->toBe(TimeTrialOutcome::Declined);
});

it('takes one answer per trial', function (TimeTrialOutcome $outcome): void {
    $session = trialDay($this->user, ['time_trial_outcome' => $outcome]);
    trialRun($this->user, 5_000.0, 1_700);

    expect(fn () => $this->trials->answer($this->user, $session, true))->toThrow(ValidationException::class);
})->with([TimeTrialOutcome::Confirmed, TimeTrialOutcome::Declined]);

it('refuses a yes with no run left on the day to count', function (): void {
    $session = trialDay($this->user, ['time_trial_outcome' => TimeTrialOutcome::Asked]);

    expect(fn () => $this->trials->answer($this->user, $session, true))->toThrow(ValidationException::class)
        ->and($session->fresh()->time_trial_outcome)->toBe(TimeTrialOutcome::Asked);
});

it('refuses another athlete\'s trial', function (): void {
    $session = trialDay(User::factory()->create(), ['time_trial_outcome' => TimeTrialOutcome::Asked]);

    expect(fn () => $this->trials->answer($this->user, $session, false))->toThrow(AuthorizationException::class);
});
