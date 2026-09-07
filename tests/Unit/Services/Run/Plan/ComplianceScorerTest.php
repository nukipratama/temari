<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\ComplianceScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function scorerDay(User $user, string $date, array $attributes = []): PlannedSession
{
    return PlannedSession::factory()->for($user)->create([
        'date' => $date,
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Easy,
        ...$attributes,
    ]);
}

function scorerRun(User $user, string $date, float $km): void
{
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::parse($date.' 06:00:00'),
        'distance' => $km * 1000,
    ]);
}

it('records a day still in progress once the run has already earned it', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05');
    scorerRun($user, '2026-08-05', 40.0);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Overreached)
        ->and($row->compliance_score)->toBeGreaterThan(0);
});

it('leaves a day still in progress planned when the run falls short', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05');
    scorerRun($user, '2026-08-05', 0.2);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Planned)
        ->and($row->compliance_score)->toBeNull();
});

it('lifts a past day the daily pass already wrote off, which nothing else would revisit', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'status' => PlannedSessionStatus::Missed,
        'compliance_score' => 0,
    ]);
    scorerRun($user, '2026-08-05', 40.0);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-07'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Overreached);
});

it('never writes a day down: a smaller verdict leaves the earned one standing', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'status' => PlannedSessionStatus::Overreached,
        'compliance_score' => 400,
    ]);
    // Comfortably credited, but far below the 400 already on the row.
    scorerRun($user, '2026-08-05', 4.0);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-07'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Overreached)
        ->and($row->compliance_score)->toBe(400);
});

it('leaves an excused day alone, however much was logged against it', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'status' => PlannedSessionStatus::Skip,
        'skipped' => true,
    ]);
    scorerRun($user, '2026-08-05', 40.0);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-07'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Skip);
});

it('does nothing on a date the plan never asked for', function (): void {
    $user = User::factory()->create();
    scorerRun($user, '2026-08-05', 40.0);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-07'));

    expect(PlannedSession::query()->count())->toBe(0);
});

it('returns no verdicts for an empty set of rows', function (): void {
    $user = User::factory()->create();

    expect(app(ComplianceScorer::class)->verdictsFor($user, PlannedSession::query()->whereRaw('1 = 0')->get(), Carbon::parse('2026-08-07')))
        ->toBe([]);
});

it('credits a run whose local date is a day ahead of the server clock', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-06');
    scorerRun($user, '2026-08-06', 40.0);

    // The activity's own local date (2026-08-06) is ahead of $today
    // (2026-08-05) — e.g. a WIT athlete just after their local midnight,
    // while the server's Asia/Jakarta clock still reads the previous day.
    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-06'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Overreached);
});
