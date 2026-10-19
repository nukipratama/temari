<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Enums\SessionType;
use App\Enums\TimeTrialOutcome;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PerformanceEvidence;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\TimeTrial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-07 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** @return array{user: User, trial: PlannedSession} */
function timeTrialFixture(): array
{
    $user = User::factory()->create();
    $trial = PlannedSession::factory()->for($user)->create([
        'date' => '2026-10-06',
        'session_type' => SessionType::Interval,
        'prescribed_hard_minutes' => 25,
        'prescribed_pace_band' => PaceBand::Interval,
        'prescribed_pace_sec_per_km' => 300,
        'prescription_race_context' => new TimeTrial(5_000, 1_500)->context(),
        'time_trial_outcome' => TimeTrialOutcome::Asked,
    ]);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => '2026-10-06 06:30:00', 'distance' => 5_010.0, 'elapsed_time' => 1_700, 'moving_time' => 1_700]);

    return ['user' => $user, 'trial' => $trial];
}

it('shows the ask on Home until it is answered', function (bool $allOut, TimeTrialOutcome $outcome, int $evidence): void {
    ['user' => $user, 'trial' => $trial] = timeTrialFixture();
    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('pendingTimeTrial', ['id' => $trial->id, 'date' => '2026-10-06', 'distance_m' => 5_000]));

    $this->actingAs($user)
        ->post("/plan/time-trials/{$trial->id}", ['all_out' => $allOut])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($trial->refresh()->time_trial_outcome)->toBe($outcome)
        ->and(PerformanceEvidence::query()->count())->toBe($evidence);
    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page->where('pendingTimeTrial', null));
})->with([
    'yes, count it' => [true, TimeTrialOutcome::Confirmed, 1],
    'no, it was not all-out' => [false, TimeTrialOutcome::Declined, 0],
]);

it('refuses a second answer', function (): void {
    ['user' => $user, 'trial' => $trial] = timeTrialFixture();
    $this->actingAs($user)->post("/plan/time-trials/{$trial->id}", ['all_out' => false]);

    $this->actingAs($user)
        ->post("/plan/time-trials/{$trial->id}", ['all_out' => true])
        ->assertSessionHasErrors('answer');

    expect($trial->refresh()->time_trial_outcome)->toBe(TimeTrialOutcome::Declined);
});

it('forbids answering another athlete\'s trial', function (): void {
    ['trial' => $trial] = timeTrialFixture();
    $this->actingAs(User::factory()->create())
        ->post("/plan/time-trials/{$trial->id}", ['all_out' => true])
        ->assertForbidden();
});
