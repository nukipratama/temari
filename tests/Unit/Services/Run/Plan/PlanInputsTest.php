<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanInputs;
use Illuminate\Support\Carbon;

/** The Monday the arc opens on, and the Saturday the goal race is run. */
const ARC_OPENS = '2026-09-07';

const GOAL_RACE_DAY = '2026-10-31';

/**
 * The prod athlete `tests/Feature/Plan` walks the arc for: experienced, four
 * sessions a week on Mon/Wed/Fri with a Saturday long run, chasing a 10K.
 * Stated as inputs rather than seeded, so the arc it trains is asserted
 * without a database.
 */
function arcInputs(
    string $today = ARC_OPENS,
    bool $deload = false,
    AdaptationReason $reason = AdaptationReason::Steady,
    ?string $raceDay = GOAL_RACE_DAY,
): PlanInputs {
    return new PlanInputs(
        userId: 1,
        today: Carbon::parse($today),
        seasonStart: Carbon::parse(ARC_OPENS),
        seasonEnd: Carbon::parse($raceDay ?? '2026-11-29'),
        seasonOpensWithRecovery: false,
        raceDate: $raceDay === null ? null : Carbon::parse($raceDay),
        raceDistanceM: $raceDay === null ? null : 10_000.0,
        sessionsPerWeek: 4,
        runDays: [1, 3, 5, 6],
        longRunDay: 6,
        adaptation: ['reason' => $reason, 'deload' => $deload, 'quality_delta' => 0, 'adherence_pct' => 100],
        pinnedDates: [],
        settledDates: [],
        projectedRaceSeconds: 3_540.0,
    );
}

/**
 * The phase and multiplier the athlete is asked to train on each of the first
 * `$weeks` Mondays, read the way production does — one regeneration per week,
 * off the same anchored season.
 *
 * @return list<array{phase: PlanPhase, multiplier: float}>
 */
function trainedArcRows(int $weeks): array
{
    $periodizer = app(Periodizer::class);

    $arc = [];
    for ($i = 0; $i < $weeks; $i++) {
        $monday = Carbon::parse(ARC_OPENS)->addWeeks($i);
        $row = $periodizer->rowsFor(arcInputs($monday->toDateString()))[$monday->toDateString()];

        $arc[] = ['phase' => $row['phase'], 'multiplier' => round($row['volume_multiplier'], 3)];
    }

    return $arc;
}

it('counts the arc from the season it belongs to, not from today', function (): void {
    $inputs = arcInputs(today: '2026-09-23'); // a Wednesday, four weeks in

    expect($inputs->arcStart()->toDateString())->toBe(ARC_OPENS)
        ->and($inputs->currentWeekStart()->toDateString())->toBe('2026-09-21');
});

it('materializes the whole horizon from the week being trained', function (): void {
    expect(arcInputs()->horizonEnd()->toDateString())
        ->toBe(Carbon::parse(ARC_OPENS)->addWeeks(Periodizer::HORIZON_WEEKS - 1)->addDays(6)->toDateString());
});

it('is self-scaled exactly when there is no race to aim at', function (): void {
    expect(arcInputs()->isSelfScaled())->toBeFalse()
        ->and(arcInputs(raceDay: null)->isSelfScaled())->toBeTrue();
});

it('trains every phase of the arc rather than restarting it each Monday', function (): void {
    expect(array_column(trainedArcRows(8), 'phase'))->toBe([
        PlanPhase::Base,
        PlanPhase::Base,
        PlanPhase::Build,
        PlanPhase::Deload,
        PlanPhase::Build,
        PlanPhase::Peak,
        PlanPhase::Peak,
        PlanPhase::Taper,
    ]);
});

it('trains a ramp that builds, dips through the scheduled deload and tapers', function (): void {
    // Base flat, Build's first week off it, the scheduled Deload at -35%,
    // Build resuming its 7.5% compounding across the dip, Peak just under
    // that, and race week at 40% of Peak.
    expect(array_column(trainedArcRows(8), 'multiplier'))->toBe([1.0, 1.0, 1.0, 0.65, 1.075, 0.989, 0.989, 0.396]);
});

it('turns the week the adapter called down into a real deload', function (): void {
    $secondMonday = Carbon::parse(ARC_OPENS)->addWeek()->toDateString();
    $row = app(Periodizer::class)->rowsFor(
        arcInputs($secondMonday, deload: true, reason: AdaptationReason::MissedWeek),
    )[$secondMonday];

    expect($row['phase'])->toBe(PlanPhase::Deload)
        ->and(round($row['volume_multiplier'], 3))->toBe(0.65);
});

it('asks for nothing harder than easy running in a deload week', function (): void {
    $monday = Carbon::parse(ARC_OPENS)->addWeek();
    $rows = app(Periodizer::class)->rowsFor(
        arcInputs($monday->toDateString(), deload: true, reason: AdaptationReason::MissedWeek),
    );

    $types = [];
    foreach (range(0, 6) as $offset) {
        $types[] = $rows[$monday->copy()->addDays($offset)->toDateString()]['session_type'];
    }

    expect($types)->not->toContain(SessionType::Tempo)
        ->and($types)->not->toContain(SessionType::Interval);
});

it('never deloads a taper week, where freshness is already the goal', function (): void {
    $taperMonday = Carbon::parse(ARC_OPENS)->addWeeks(7)->toDateString();
    $row = app(Periodizer::class)->rowsFor(
        arcInputs($taperMonday, deload: true, reason: AdaptationReason::MissedWeek),
    )[$taperMonday];

    expect($row['phase'])->toBe(PlanPhase::Taper);
});

it('puts race day in the plan at the distance the goal names', function (): void {
    $raceWeekMonday = Carbon::parse(ARC_OPENS)->addWeeks(7)->toDateString();
    $rows = app(Periodizer::class)->rowsFor(arcInputs($raceWeekMonday));

    expect($rows[GOAL_RACE_DAY]['session_type'])->toBe(SessionType::Race);
});

it('builds a self-scaled cycle of its own when the athlete has no race', function (): void {
    $rows = app(Periodizer::class)->rowsFor(arcInputs(raceDay: null));

    $phases = collect($rows)->pluck('phase')->unique()->values()->all();

    expect($phases)->not->toContain(PlanPhase::Taper)
        ->and(collect($rows)->pluck('session_type')->all())->not->toContain(SessionType::Race);
});
