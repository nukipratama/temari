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

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-07 10:00:00');
    $this->user = User::factory()->create();
    $this->trial = PlannedSession::factory()->for($this->user)->create([
        'date' => '2026-10-06',
        'session_type' => SessionType::Interval,
        'prescribed_hard_minutes' => 25,
        'prescribed_pace_band' => PaceBand::Interval,
        'prescribed_pace_sec_per_km' => 300,
        'prescription_race_context' => new TimeTrial(5_000, 1_500)->context(),
        'time_trial_outcome' => TimeTrialOutcome::Asked,
    ]);
    $activity = Activity::factory()->for($this->user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => '2026-10-06 06:30:00', 'distance' => 5_010.0, 'elapsed_time' => 1_700, 'moving_time' => 1_700]);
});
afterEach(fn () => Carbon::setTestNow());

it('requires authentication', function (): void {
    $this->post("/plan/time-trials/{$this->trial->id}", ['all_out' => true])->assertRedirect('/login');
});

it('shows the ask on Home until it is answered', function (bool $allOut, TimeTrialOutcome $outcome, int $evidence): void {
    $this->actingAs($this->user)->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('pendingTimeTrial', ['id' => $this->trial->id, 'date' => '2026-10-06', 'distance_m' => 5_000]));

    $this->actingAs($this->user)
        ->post("/plan/time-trials/{$this->trial->id}", ['all_out' => $allOut])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->trial->fresh()->time_trial_outcome)->toBe($outcome)
        ->and(PerformanceEvidence::query()->count())->toBe($evidence);
    $this->actingAs($this->user)->get('/')->assertInertia(fn (Assert $page) => $page->where('pendingTimeTrial', null));
})->with([
    'yes, count it' => [true, TimeTrialOutcome::Confirmed, 1],
    'no, it was not all-out' => [false, TimeTrialOutcome::Declined, 0],
]);

it('refuses a second answer', function (): void {
    $this->actingAs($this->user)->post("/plan/time-trials/{$this->trial->id}", ['all_out' => false]);

    $this->actingAs($this->user)
        ->post("/plan/time-trials/{$this->trial->id}", ['all_out' => true])
        ->assertSessionHasErrors('answer');

    expect($this->trial->fresh()->time_trial_outcome)->toBe(TimeTrialOutcome::Declined);
});

it('forbids answering another athlete\'s trial', function (): void {
    $this->actingAs(User::factory()->create())
        ->post("/plan/time-trials/{$this->trial->id}", ['all_out' => true])
        ->assertForbidden();
});
