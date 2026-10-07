<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\PaceBand;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create();

    expect($session->user)->toBeInstanceOf(User::class)
        ->and($session->user->is($user))->toBeTrue();
});

it('casts date and every enum column', function (): void {
    $session = PlannedSession::factory()->make([
        'date' => '2026-08-17',
        'phase' => 'build',
        'session_type' => 'tempo',
        'pinned' => 1,
        'skipped' => 0,
        'status' => 'overreached',
        'compliance_score' => '145',
        'ran_anyway' => 1,
        'prescribed_hard_minutes' => '20',
        'prescribed_pace_band' => 'threshold',
        'prescribed_pace_sec_per_km' => '270',
        'prescription_race_context' => ['kind' => 'marathon'],
    ]);

    expect($session->date)->toBeInstanceOf(Carbon::class)
        ->and($session->phase)->toBe(PlanPhase::Build)
        ->and($session->session_type)->toBe(SessionType::Tempo)
        ->and($session->pinned)->toBeTrue()
        ->and($session->skipped)->toBeFalse()
        ->and($session->status)->toBe(PlannedSessionStatus::Overreached)
        ->and($session->compliance_score)->toBe(145)
        ->and($session->prescribed_hard_minutes)->toBe(20)
        ->and($session->prescribed_pace_band)->toBe(PaceBand::Threshold)
        ->and($session->prescribed_pace_sec_per_km)->toBe(270)
        ->and($session->prescription_race_context)->toBe(['kind' => 'marathon'])
        ->and($session->ran_anyway)->toBeTrue();
});

it('serializes date as the naive date, not a UTC-shifted instant', function (): void {
    $session = new PlannedSession(['date' => '2026-08-17']);

    expect($session->toArray()['date'])->toBe('2026-08-17');
});

it('serializes made_up_on as the naive date, not a UTC-shifted instant', function (): void {
    $session = new PlannedSession(['made_up_on' => '2026-08-18']);

    expect($session->toArray()['made_up_on'])->toBe('2026-08-18');
});

it('enforces one row per user per date', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-08-17']);

    expect(fn () => PlannedSession::factory()->for($user)->create(['date' => '2026-08-17']))
        ->toThrow(QueryException::class);
});

it('isExcused covers both an athlete skip and a recorded rest clamp', function (): void {
    $user = User::factory()->create();

    $plain = PlannedSession::factory()->for($user)->create(['date' => '2026-08-10']);
    $skipped = PlannedSession::factory()->for($user)->create(['date' => '2026-08-11', 'skipped' => true]);
    $clamped = PlannedSession::factory()->for($user)->create([
        'date' => '2026-08-12',
        'rest_clamped_at' => Carbon::parse('2026-08-12 06:00:00'),
    ]);

    expect($plain->isExcused())->toBeFalse()
        ->and($skipped->isExcused())->toBeTrue()
        ->and($clamped->isExcused())->toBeTrue();
});
