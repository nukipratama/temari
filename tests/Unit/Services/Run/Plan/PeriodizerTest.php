<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->periodizer = app(Periodizer::class);
});
afterEach(fn () => Carbon::setTestNow());

function seedPeriodizerBaseline(User $user): void
{
    foreach (range(0, 3) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => 4,
            'distance_km' => 30.0,
        ]);
    }
}

it('generates a self-scaled build/deload cycle when the user has no active race', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    $this->periodizer->regenerate($user, Carbon::today());

    $phases = PlannedSession::query()->where('user_id', $user->id)->pluck('phase')->map(fn ($p) => $p->value)->unique()->sort()->values()->all();
    expect($phases)->toBe(['build', 'deload']);
});

it('generates a race-oriented base/build/peak/taper progression when an active race exists', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    // Close enough (weeksToRace = 10) that base/build/peak/taper all fall
    // within the periodizer's 12-week materialization horizon.
    RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(9)->toDateString(),
        'distance_m' => 10_000,
    ]);

    $this->periodizer->regenerate($user, Carbon::today());

    $phases = PlannedSession::query()->where('user_id', $user->id)->pluck('phase')->map(fn ($p) => $p->value)->unique()->all();
    expect($phases)->toContain('base', 'build', 'peak', 'taper')
        ->and($phases)->not->toContain('deload');
});

it('never overwrites a pinned row', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $pinned = PlannedSession::factory()->for($user)->pinned()->create([
        'date' => Carbon::today()->addDays(2)->toDateString(),
        'session_type' => SessionType::Interval,
    ]);

    $this->periodizer->regenerate($user, Carbon::today());

    $fresh = $pinned->fresh();
    expect($fresh->session_type)->toBe(SessionType::Interval)
        ->and($fresh->pinned)->toBeTrue();
});

it('never touches a row dated before today', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $past = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->subDays(3)->toDateString(),
        'session_type' => SessionType::Rest,
    ]);

    $this->periodizer->regenerate($user, Carbon::today());

    expect($past->fresh()->session_type)->toBe(SessionType::Rest);
});

it('recomputes a stale unpinned future row fresh on a second call', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    $this->periodizer->regenerate($user, Carbon::today());
    $someRow = PlannedSession::query()
        ->where('user_id', $user->id)
        ->where('date', '>=', Carbon::today()->toDateString())
        ->first();
    $dateKey = $someRow->date->toDateString();
    $someRow->update(['session_type' => SessionType::Interval, 'phase' => PlanPhase::Peak]);

    $this->periodizer->regenerate($user, Carbon::today());

    // Self-scaled mode never produces Peak, so a still-Peak row would prove the
    // stale edit survived instead of being recomputed.
    $refetched = PlannedSession::query()->where('user_id', $user->id)->where('date', $dateKey)->first();
    expect($refetched)->not->toBeNull()
        ->and($refetched->phase)->not->toBe(PlanPhase::Peak);
});

it('cleans up stale far-future rows when the horizon shrinks after setting a near-term race', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    $this->periodizer->regenerate($user, Carbon::today());
    $farFutureDate = Carbon::today()->addWeeks(10)->toDateString();
    expect(PlannedSession::query()->where('user_id', $user->id)->where('date', '>=', $farFutureDate)->exists())->toBeTrue();

    RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(3)->toDateString(),
        'distance_m' => 10_000,
    ]);
    $this->periodizer->regenerate($user, Carbon::today());

    expect(PlannedSession::query()->where('user_id', $user->id)->where('date', '>=', $farFutureDate)->exists())->toBeFalse();
});

it('also ensures a current season exists, in lockstep with the plan\'s own mode', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);

    $this->periodizer->regenerate($user, Carbon::today());

    expect(Season::query()->where('user_id', $user->id)->where('race_goal_id', null)->exists())->toBeTrue();
});

it('leaves a pinned far-future row alone even when the horizon shrinks', function (): void {
    $user = User::factory()->create();
    seedPeriodizerBaseline($user);
    $pinned = PlannedSession::factory()->for($user)->pinned()->create([
        'date' => Carbon::today()->addWeeks(11)->toDateString(),
    ]);

    RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(3)->toDateString(),
        'distance_m' => 10_000,
    ]);
    $this->periodizer->regenerate($user, Carbon::today());

    expect(PlannedSession::query()->find($pinned->id))->not->toBeNull();
});
