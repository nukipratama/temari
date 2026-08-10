<?php

declare(strict_types=1);

use App\Enums\DistanceBand;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
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

it('restHonoredCountForUser counts a past rest day with no logged activity', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->rest()->create(['date' => Carbon::today()->subDay()->toDateString()]);

    expect(PlannedSession::restHonoredCountForUser($user->id, Carbon::today()))->toBe(1);

    Carbon::setTestNow();
});

it('restHonoredCountForUser does not count a rest day an activity was logged on', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    $date = Carbon::today()->subDay();
    PlannedSession::factory()->for($user)->rest()->create(['date' => $date->toDateString()]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->create())->create(['start_date_local' => $date]);

    expect(PlannedSession::restHonoredCountForUser($user->id, Carbon::today()))->toBe(0);

    Carbon::setTestNow();
});

it('restHonoredCountForUser ignores today and future rest days', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->rest()->create(['date' => Carbon::today()->toDateString()]);
    PlannedSession::factory()->for($user)->rest()->create(['date' => Carbon::today()->addDay()->toDateString()]);

    expect(PlannedSession::restHonoredCountForUser($user->id, Carbon::today()))->toBe(0);

    Carbon::setTestNow();
});

it('restHonoredCountForUser scopes to a date range when given one', function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->rest()->create(['date' => Carbon::today()->subDays(10)->toDateString()]);
    PlannedSession::factory()->for($user)->rest()->create(['date' => Carbon::today()->subDays(2)->toDateString()]);

    $count = PlannedSession::restHonoredCountForUser(
        $user->id,
        Carbon::today(),
        Carbon::today()->subDays(5),
        Carbon::today(),
    );

    expect($count)->toBe(1);

    Carbon::setTestNow();
});
