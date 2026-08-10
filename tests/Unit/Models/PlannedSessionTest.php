<?php

declare(strict_types=1);

use App\Enums\DistanceBand;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
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
        'distance_band' => 'medium',
        'pace_band' => 'threshold',
        'pinned' => 1,
        'status' => 'planned',
    ]);

    expect($session->date)->toBeInstanceOf(Carbon::class)
        ->and($session->phase)->toBe(PlanPhase::Build)
        ->and($session->session_type)->toBe(SessionType::Tempo)
        ->and($session->distance_band)->toBe(DistanceBand::Medium)
        ->and($session->pace_band)->toBe(PaceBand::Threshold)
        ->and($session->pinned)->toBeTrue()
        ->and($session->status)->toBe(PlannedSessionStatus::Planned);
});

it('serializes date as the naive date, not a UTC-shifted instant', function (): void {
    $session = new PlannedSession(['date' => '2026-08-17']);

    expect($session->toArray()['date'])->toBe('2026-08-17');
});

it('allows a null pace_band, for rest days', function (): void {
    $session = PlannedSession::factory()->rest()->make();

    expect($session->pace_band)->toBeNull();
});

it('enforces one row per user per date', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-08-17']);

    expect(fn () => PlannedSession::factory()->for($user)->create(['date' => '2026-08-17']))
        ->toThrow(QueryException::class);
});
