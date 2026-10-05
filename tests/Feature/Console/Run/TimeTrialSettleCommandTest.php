<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Enums\SessionType;
use App\Enums\TimeTrialOutcome;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\InboxNotification;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\TimeTrial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-07 09:05:00'));

afterEach(fn () => Carbon::setTestNow());

function settledTrial(User $user, string $date, float $runMeters = 5_000.0, int $runSeconds = 1_800): PlannedSession
{
    $session = PlannedSession::factory()->for($user)->create([
        'date' => $date,
        'session_type' => SessionType::Interval,
        'prescribed_hard_minutes' => 25,
        'prescribed_pace_band' => PaceBand::Interval,
        'prescribed_pace_sec_per_km' => 300,
        'prescription_race_context' => new TimeTrial(5_000, 1_500)->context(),
    ]);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => "{$date} 06:30:00", 'distance' => $runMeters, 'elapsed_time' => $runSeconds, 'moving_time' => $runSeconds]);

    return $session;
}

it('settles each finished trial of the last week once, and asks only once', function (): void {
    $user = User::factory()->create();
    $asked = settledTrial($user, '2026-10-06');
    $counted = settledTrial(User::factory()->create(), '2026-10-01', runSeconds: 1_500);

    $this->artisan('plan:settle-time-trials')->expectsOutputToContain('Settled 2 time trial(s).')->assertSuccessful();
    $this->artisan('plan:settle-time-trials')->expectsOutputToContain('Settled 0 time trial(s).')->assertSuccessful();

    expect($asked->fresh()->time_trial_outcome)->toBe(TimeTrialOutcome::Asked)
        ->and($counted->fresh()->time_trial_outcome)->toBe(TimeTrialOutcome::Confirmed)
        ->and(InboxNotification::query()->where('user_id', $user->id)->where('kind', 'time_trial')->count())->toBe(1);
});

it('leaves today, trials past the lookback, other days and the demo athlete alone', function (): void {
    $today = settledTrial(User::factory()->create(), '2026-10-07');
    $old = settledTrial(User::factory()->create(), '2026-09-29');
    $demo = settledTrial(User::factory()->create(['is_demo' => true]), '2026-10-06');
    $plain = settledTrial(User::factory()->create(), '2026-10-06');
    $plain->update(['prescription_race_context' => null]);

    $this->artisan('plan:settle-time-trials')->expectsOutputToContain('Settled 0 time trial(s).')->assertSuccessful();

    expect([$today, $old, $demo, $plain])->each(fn ($session) => $session->fresh()->time_trial_outcome->toBeNull());
});
