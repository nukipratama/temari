<?php

declare(strict_types=1);

use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\PlannedSessionTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function plannedTypesRun(User $user, string $at, bool $analyzed = true): void
{
    $factory = Activity::factory()->for($user);
    $activity = ($analyzed ? $factory : $factory->stub())->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => $at]);
}

function plannedTypesWindow(User $user): array
{
    return PlannedSessionTypes::byDate($user->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30 23:59:59'));
}

it('keys the planned type by the date of the one run that landed on it', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-09-10', 'session_type' => SessionType::Tempo]);
    plannedTypesRun($user, '2026-09-10 07:00:00');

    expect(plannedTypesWindow($user))->toBe(['2026-09-10' => SessionType::Tempo]);
});

it('drops a date with two runs, since neither can claim the session', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-09-10', 'session_type' => SessionType::Tempo]);
    plannedTypesRun($user, '2026-09-10 07:00:00');
    plannedTypesRun($user, '2026-09-10 18:00:00');

    expect(plannedTypesWindow($user))->toBe([]);
});

it('ignores rest days, excused sessions and dates without an analyzed run', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-09-10', 'session_type' => SessionType::Rest]);
    PlannedSession::factory()->for($user)->create(['date' => '2026-09-11', 'session_type' => SessionType::Easy, 'skipped' => true]);
    PlannedSession::factory()->for($user)->create(['date' => '2026-09-12', 'session_type' => SessionType::Long]);
    plannedTypesRun($user, '2026-09-10 07:00:00');
    plannedTypesRun($user, '2026-09-11 07:00:00');
    plannedTypesRun($user, '2026-09-12 07:00:00', analyzed: false);

    expect(plannedTypesWindow($user))->toBe([]);
});

it('only reads the given user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    PlannedSession::factory()->for($other)->create(['date' => '2026-09-10', 'session_type' => SessionType::Tempo]);
    plannedTypesRun($other, '2026-09-10 07:00:00');

    expect(plannedTypesWindow($user))->toBe([]);
});
