<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Story\RecoveryWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function seedRecoveryRun(User $user, string $startLocal): void
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($startLocal),
    ]);
}

it('returns empty window when the user has never run', function (): void {
    $user = User::factory()->create();

    $w = RecoveryWindow::forUser($user, Carbon::parse('2026-05-18 12:00'));

    expect($w->hoursSinceLastRun)->toBeNull()
        ->and($w->ranToday)->toBeFalse()
        ->and($w->daysSinceLastRun)->toBeNull()
        ->and($w->recoveryHours)->toBeNull();
});

it('measures from the most recent run when the last run was a previous day', function (): void {
    $asOf = Carbon::parse('2026-05-18 12:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();
    seedRecoveryRun($user, '2026-05-17 06:00');

    $w = RecoveryWindow::forUser($user, $asOf);

    expect($w->hoursSinceLastRun)->toBe(30)
        ->and($w->ranToday)->toBeFalse()
        ->and($w->daysSinceLastRun)->toBe(1)
        ->and($w->recoveryHours)->toBe(30);
});

it('reframes recovery from the prior run day when the last run was today', function (): void {
    // The bug this fixes: a run logged this morning must not report ~0 recovery.
    $asOf = Carbon::parse('2026-05-18 12:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();
    seedRecoveryRun($user, '2026-05-16 07:00'); // prior distinct day
    seedRecoveryRun($user, '2026-05-18 06:00'); // this morning

    $w = RecoveryWindow::forUser($user, $asOf);

    expect($w->ranToday)->toBeTrue()
        ->and($w->hoursSinceLastRun)->toBe(6)          // literal recency, for the chip
        ->and($w->daysSinceLastRun)->toBe(0)
        ->and($w->recoveryHours)->toBe(53);            // from the 05-16 run, not the 05-18 one
});

it('ignores runs after asOf so a backdated recompute stays historical', function (): void {
    // Self-heal / dead-letter retry recomputes a past-dated briefing. A run
    // logged after that date must not leak into it.
    Carbon::setTestNow(Carbon::parse('2026-05-25 09:00'));
    $asOf = Carbon::parse('2026-05-18 00:00'); // a past briefing date
    $user = User::factory()->create();
    seedRecoveryRun($user, '2026-05-17 06:00'); // real prior run, as of 05-18
    seedRecoveryRun($user, '2026-05-24 07:00'); // happened AFTER the briefing date

    $w = RecoveryWindow::forUser($user, $asOf);

    // Measured from the 05-17 run against end of 05-18, not the 05-24 run.
    expect($w->ranToday)->toBeFalse()
        ->and($w->recoveryHours)->toBe(41) // 05-17 06:00 -> 05-18 23:59:59
        ->and($w->daysSinceLastRun)->toBe(1);
});

it('reports unknown recovery when today is the only run on record', function (): void {
    // A first-ever run, viewed the same day it was logged: there is no prior
    // session to measure recovery from, so recovery is null (unknown), never
    // the misleading ~0 that resurfaces the "0 jam" bug at ingest time.
    $asOf = Carbon::parse('2026-05-18 12:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();
    seedRecoveryRun($user, '2026-05-18 06:00');

    $w = RecoveryWindow::forUser($user, $asOf);

    expect($w->ranToday)->toBeTrue()
        ->and($w->hoursSinceLastRun)->toBe(6) // recency chip still has the literal value
        ->and($w->recoveryHours)->toBeNull();
});
