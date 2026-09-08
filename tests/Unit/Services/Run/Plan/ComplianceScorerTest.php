<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\PlanRenderer;
use App\Services\Run\Plan\TrainingBaseline;
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

it('records what the day was judged against, so the denominator outlives the baseline', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05');
    scorerRun($user, '2026-08-05', 40.0);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->prescribed_km)->toBeGreaterThan(0.0);
});

it('measures a day against the baseline as it stood that day, not against today\'s', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05');
    scorerRun($user, '2026-08-05', 40.0);

    // Two weeks of history the athlete only accumulates AFTER the day being
    // judged. Scoring as-of today would measure the day against them.
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-08-09', 'distance_km' => 120.0, 'runs' => 6]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-08-16', 'distance_km' => 140.0, 'runs' => 6]);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-20'));

    $asOf = app(TrainingBaseline::class)->forUser($user, Carbon::parse('2026-08-05'))['long_run_km'];
    $expected = PlanRenderer::plannedKmByDate(PlannedSession::query()->where('user_id', $user->id)->get(), $asOf)['2026-08-05'];

    expect($row->refresh()->prescribed_km)->toBe($expected);
});

/**
 * The bug this closes: an athlete told at 00:01 to run an eased 3.6 instead of
 * the 5.9 tempo on the board, who obeys, was graded 3.6/5.9 = 61% and landed on
 * `partial` for following the app's own advice.
 */
it('grades a clamped day against the eased distance it actually asked for', function (): void {
    $user = User::factory()->create();
    // 1.2 against a stored tempo ask of ~3.4 is 35% — `partial` — without the
    // substitution, which is exactly the shape of the reported bug.
    $row = scorerDay($user, '2026-08-05', [
        'session_type' => SessionType::Tempo,
        'clamped_km' => 1.2,
    ]);
    scorerRun($user, '2026-08-05', 1.2);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Done)
        ->and($row->compliance_score)->toBe(100)
        // The ask recorded beside the verdict is the one they were set, so the
        // card cannot show "3.4 asked · 1.2 run · DONE".
        ->and($row->prescribed_km)->toBe(1.2);
});

/** Told to do less and doing the original session anyway is an overreach. */
it('reads the full original session on a clamped day as going past the ask', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'session_type' => SessionType::Tempo,
        'clamped_km' => 1.2,
    ]);
    scorerRun($user, '2026-08-05', 3.4);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Overreached);
});

/** No clamp recorded, nothing changes: the stored session is still the ask. */
it('grades an unclamped day against the stored session exactly as before', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo]);
    scorerRun($user, '2026-08-05', 3.6);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    // The stored figure is whatever the renderer derives for a tempo at this
    // baseline; the point is only that 3.6 was NOT substituted in for it.
    expect($row->refresh()->clamped_km)->toBeNull()
        ->and($row->prescribed_km)->not->toBe(3.6)
        ->and($row->prescribed_km)->toBeGreaterThan(0.0);
});
