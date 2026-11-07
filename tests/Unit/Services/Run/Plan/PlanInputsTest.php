<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\FallOffTilt;
use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Services\Run\Plan\IntensityPrescription;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PostRaceRecovery;
use App\Services\Run\Plan\SegmentGenerator;
use Illuminate\Support\Carbon;

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
        PlanPhase::Build,
        PlanPhase::Build,
        PlanPhase::Deload,
        PlanPhase::Peak,
        PlanPhase::Peak,
        PlanPhase::Taper,
        PlanPhase::Taper,
    ]);
});

it('trains a ramp that builds, dips through the scheduled deload and tapers', function (): void {
    // Base flat, Build compounding 7.5%, the scheduled Deload at -35% off it,
    // Peak holding the build level, and the taper at 60% then 40% of it.
    expect(array_column(trainedArcRows(8), 'multiplier'))->toBe([1.0, 1.0, 1.075, 0.699, 1.075, 1.075, 0.645, 0.43]);
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

it('holds a race block flat outside its dips while its increases are held', function (): void {
    $held = arcInputs();
    $held = planInputsWith($held, ['increasesHeld' => true]);

    $multipliers = array_unique(array_map(
        fn (array $row): float => round($row['volume_multiplier'], 3),
        app(Periodizer::class)->rowsFor($held),
    ));
    sort($multipliers);

    expect($multipliers)->toBe([0.4, 0.6, 0.65, 1.0]);
});

it('keeps prescribed hard work inside the real weekly time budget', function (): void {
    $inputs = arcInputs(today: '2026-09-21');
    $inputs = planInputsWith($inputs, [
        'sessionsPerWeek' => 5,
        'raceDistanceM' => 10_000.0,
        'raceGoalTimeSec' => 3_600,
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 28.0,
        'longRunCapKm' => 28.0,
        'longRunProgressionCapKm' => 28.0,
        'recentPrescriptions' => [
            'tempo' => ['verdict' => IntentVerdict::Hit, 'hard_minutes' => 30],
            'interval' => ['verdict' => IntentVerdict::Hit, 'hard_minutes' => 12],
        ],
    ]);

    $weekStart = Carbon::parse('2026-09-21');
    $weekRows = array_filter(
        app(Periodizer::class)->rowsFor($inputs),
        static fn (array $row, string $date): bool => $date >= $weekStart->toDateString() && $date <= $weekStart->copy()->addDays(6)->toDateString(),
        ARRAY_FILTER_USE_BOTH,
    );
    $firstEasy = array_find_key($weekRows, static fn (array $row): bool => $row['session_type'] === SessionType::Easy);
    $totalMinutes = 0.0;
    $hardMinutes = 0;
    $hardDays = 0;

    foreach ($weekRows as $date => $row) {
        $km = SegmentGenerator::coreKmFor(
            $row['session_type'],
            $date === $firstEasy,
            $inputs->longRunBaselineKm,
            $row['volume_multiplier'],
            $inputs->longRunCapKm,
            $inputs->raceDistanceM,
            $inputs->longRunProgressionCapKm,
        );
        $prescription = new IntensityPrescription(
            $row['prescribed_hard_minutes'],
            $row['prescribed_pace_band'],
            $row['prescribed_pace_sec_per_km'],
            $row['prescription_reason'],
            $row['prescription_race_context'],
        );
        $segments = $row['session_type']->isQuality()
            ? SegmentGenerator::forPrescription($row['session_type'], $row['phase'], $km, $inputs->paces, $prescription)
            : SegmentGenerator::forCoreKm($row['session_type'], $row['phase'], $inputs->raceDistanceM, $km, $inputs->paces, $inputs->raceGoalTimeSec);
        foreach ($segments as $segment) {
            $totalMinutes += $segment->minutes ?? 0.0;
        }
        if ($prescription->hardMinutes > 0) {
            $hardMinutes += $prescription->hardMinutes;
            $hardDays++;
        }
    }

    expect($hardMinutes)->toBeLessThanOrEqual((int) floor($totalMinutes * 0.3))
        ->and($hardDays)->toBeLessThanOrEqual(2);
});

it('spends completed off-menu hard minutes before allocating remaining quality', function (): void {
    $inputs = planInputsWith(arcInputs(today: '2026-09-23'), [
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 12.0,
        'actualSessions' => [
            ['date' => '2026-09-21', 'duration_minutes' => 40, 'hard_minutes' => 40.0, 'demanding' => true],
            ['date' => '2026-09-22', 'duration_minutes' => 40, 'hard_minutes' => 40.0, 'demanding' => true],
        ],
    ]);
    $rows = app(Periodizer::class)->rowsFor($inputs);
    expect($rows['2026-09-23']['prescribed_hard_minutes'])->toBe(0);
});

it('uses prior-week demanding actuals without a circular hypothetical long-run veto', function (): void {
    $inputs = planInputsWith(arcInputs(today: '2026-09-21'), [
        'runDays' => [0, 2, 4],
        'longRunDay' => 4,
        'actualSessions' => [['date' => '2026-09-20', 'duration_minutes' => 90, 'hard_minutes' => null, 'demanding' => true]],
    ]);
    $rows = app(Periodizer::class)->rowsFor($inputs);
    expect($rows['2026-09-21']['session_type'])->toBe(SessionType::Easy);
});

it('allows established two-run athletes only a modest tempo and an easy long run', function (): void {
    $inputs = planInputsWith(arcInputs(today: '2026-09-21', raceDay: null), [
        'sessionsPerWeek' => 2, 'runDays' => [2, 5], 'longRunDay' => 5,
        'twoRunQualityEligible' => true,
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 10.0,
    ]);
    $rows = app(Periodizer::class)->rowsFor($inputs);
    expect($rows['2026-09-23']['session_type'])->toBe(SessionType::Tempo)
        ->and($rows['2026-09-23']['prescribed_hard_minutes'])->toBe(10)
        ->and($rows['2026-09-26']['prescribed_hard_minutes'])->toBe(0);
});

it('uses performed easy effort instead of the original settled tempo dose', function (): void {
    $inputs = planInputsWith(arcInputs(today: '2026-09-23'), [
        'runDays' => [0, 3, 5], 'longRunDay' => 5,
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 12.0,
        'fixedSessions' => ['2026-09-21' => ['session_type' => SessionType::Tempo, 'prescribed_hard_minutes' => 100, 'prescribed_pace_band' => PaceBand::Threshold]],
        'actualSessions' => [['date' => '2026-09-21', 'duration_minutes' => 40, 'hard_minutes' => 0.0, 'demanding' => false]],
    ]);
    $rows = app(Periodizer::class)->rowsFor($inputs);
    expect($rows['2026-09-24']['prescribed_hard_minutes'])->toBeGreaterThan(0);
});

it('does not treat missing effort evidence on a completed quality slot as measured easy work', function (): void {
    $inputs = planInputsWith(arcInputs(today: '2026-09-23'), [
        'runDays' => [0, 3, 5], 'longRunDay' => 5,
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 12.0,
        'fixedSessions' => ['2026-09-21' => ['session_type' => SessionType::Tempo, 'prescribed_hard_minutes' => 20, 'prescribed_pace_band' => PaceBand::Threshold, 'hard_minutes' => null]],
        'actualSessions' => [['date' => '2026-09-21', 'duration_minutes' => 40, 'hard_minutes' => null, 'demanding' => false]],
    ]);
    $rows = app(Periodizer::class)->rowsFor($inputs);
    expect($rows['2026-09-24']['prescribed_hard_minutes'])->toBe(0)
        ->and($rows['2026-09-24']['prescription_reason'])->toContain('unmeasured hard minutes');
});

it('includes measured hard minutes even when only one completed hard day exists', function (): void {
    $inputs = planInputsWith(arcInputs(today: '2026-09-23'), [
        'runDays' => [0, 3, 5], 'longRunDay' => 5,
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 10.0,
        'actualSessions' => [['date' => '2026-09-21', 'duration_minutes' => 80, 'hard_minutes' => 80.0, 'demanding' => true]],
    ]);
    expect(app(Periodizer::class)->rowsFor($inputs)['2026-09-24']['prescribed_hard_minutes'])->toBe(0);
});

it('reserves unknown demanding work without inventing measured hard minutes', function (bool $hasPaces): void {
    $inputs = planInputsWith(arcInputs(today: '2026-09-23'), [
        'runDays' => [0, 3, 5], 'longRunDay' => 5,
        'paces' => $hasPaces ? ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240] : null,
        'longRunBaselineKm' => 12.0,
        'actualSessions' => [['date' => '2026-09-21', 'duration_minutes' => 100, 'hard_minutes' => null, 'demanding' => true]],
    ]);
    expect(app(Periodizer::class)->rowsFor($inputs)['2026-09-24']['prescribed_hard_minutes'])->toBe(0);
})->with(['known paces' => true, 'unknown paces' => false]);

it('respects known demanding days beyond the next week boundary', function (): void {
    $inputs = planInputsWith(arcInputs(today: '2026-09-21'), [
        'runDays' => [3, 5, 6], 'longRunDay' => 3,
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 12.0,
        'fixedSessions' => ['2026-09-28' => ['session_type' => SessionType::Tempo, 'prescribed_hard_minutes' => 20, 'prescribed_pace_band' => PaceBand::Threshold]],
    ]);
    expect(app(Periodizer::class)->rowsFor($inputs)['2026-09-27']['prescribed_hard_minutes'])->toBe(0);
});

it('keeps current-week quality reductions out of later weeks', function (): void {
    $inputs = arcInputs(today: '2026-09-21', raceDay: null);
    $inputs = planInputsWith($inputs, ['adaptation' => [...$inputs->adaptation, 'quality_delta' => -1], 'longRunBaselineKm' => 12.0]);
    $rows = app(Periodizer::class)->rowsFor($inputs);
    expect($rows['2026-09-24']['session_type'])->toBe(SessionType::Easy)
        ->and($rows['2026-10-08']['session_type'])->toBe(SessionType::Tempo);
});

it('releases a race-pace long prescription budget after credible easy execution', function (): void {
    $inputs = planInputsWith(arcInputs(today: '2026-09-23'), [
        'runDays' => [0, 3, 5], 'longRunDay' => 5,
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 12.0,
        'fixedSessions' => ['2026-09-21' => ['session_type' => SessionType::Long, 'prescribed_hard_minutes' => 100, 'prescribed_pace_band' => PaceBand::Marathon]],
        'actualSessions' => [['date' => '2026-09-21', 'duration_minutes' => 60, 'hard_minutes' => 0.0, 'demanding' => false]],
    ]);
    expect(app(Periodizer::class)->rowsFor($inputs)['2026-09-24']['prescribed_hard_minutes'])->toBeGreaterThan(0);
});

it('spends retained future prescription minutes together with generated work', function (): void {
    $inputs = planInputsWith(arcInputs(today: '2026-09-23'), [
        'runDays' => [0, 3, 5], 'longRunDay' => 5,
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 12.0,
        'pinnedDates' => ['2026-09-27' => true],
        'fixedSessions' => ['2026-09-27' => ['session_type' => SessionType::Tempo, 'prescribed_hard_minutes' => 100, 'prescribed_pace_band' => PaceBand::Threshold, 'duration_minutes' => 100.0]],
    ]);
    expect(app(Periodizer::class)->rowsFor($inputs)['2026-09-24']['prescribed_hard_minutes'])->toBe(0);
});

it('distinguishes credible easy race execution from unknown completed race effort', function (?float $minutes, bool $withheld): void {
    $inputs = planInputsWith(arcInputs(today: '2026-09-23'), [
        'runDays' => [0, 3, 5], 'longRunDay' => 5,
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 12.0,
        'fixedSessions' => array_fill_keys(['2026-09-21', '2026-09-22'], ['session_type' => SessionType::Race, 'prescribed_hard_minutes' => 0, 'prescribed_pace_band' => null, 'hard_minutes' => null]),
        'actualSessions' => [
            ['date' => '2026-09-21', 'duration_minutes' => 40, 'hard_minutes' => $minutes, 'demanding' => false],
            ['date' => '2026-09-22', 'duration_minutes' => 40, 'hard_minutes' => $minutes, 'demanding' => false],
        ],
    ]);
    $row = app(Periodizer::class)->rowsFor($inputs)['2026-09-24'];
    expect($row['prescribed_hard_minutes'] === 0)->toBe($withheld);
    if ($withheld) {
        expect($row['prescription_reason'])->toContain('unmeasured hard minutes');
    }
})->with(['credible easy' => [0.0, false], 'unknown completed race' => [null, true]]);

it('carries an endurance tilt onto the Build week\'s quality day and long run, and lengthens only the long run', function (): void {
    $weekStart = '2026-09-21';
    $base = planInputsWith(arcInputs(today: $weekStart), [
        'raceGoalTimeSec' => 3_540,
        'paces' => ['easy' => 380, 'marathon' => 330, 'threshold' => 305, 'interval' => 280],
        'longRunBaselineKm' => 14.0,
    ]);
    $week = static fn (array $rows): array => array_filter(
        $rows,
        static fn (string $date): bool => $date >= $weekStart && $date <= Carbon::parse($weekStart)->addDays(6)->toDateString(),
        ARRAY_FILTER_USE_KEY,
    );
    $neutral = $week(app(Periodizer::class)->rowsFor($base));
    $tilted = $week(app(Periodizer::class)->rowsFor(planInputsWith($base, ['fallOffTilt' => FallOffTilt::Endurance])));

    $types = static fn (array $rows): array => array_map(static fn (array $row): array => [$row['session_type'], $row['fall_off_tilt']], $rows);

    expect($types($neutral))->toBe([
        '2026-09-21' => [SessionType::Rest, null],
        '2026-09-22' => [SessionType::Easy, null],
        '2026-09-23' => [SessionType::Rest, null],
        '2026-09-24' => [SessionType::Interval, null],
        '2026-09-25' => [SessionType::Rest, null],
        '2026-09-26' => [SessionType::Easy, null],
        '2026-09-27' => [SessionType::Long, null],
    ])->and($types($tilted))->toBe([
        '2026-09-21' => [SessionType::Rest, null],
        '2026-09-22' => [SessionType::Easy, null],
        '2026-09-23' => [SessionType::Rest, null],
        '2026-09-24' => [SessionType::Tempo, FallOffTilt::Endurance],
        '2026-09-25' => [SessionType::Rest, null],
        '2026-09-26' => [SessionType::Easy, null],
        '2026-09-27' => [SessionType::Long, FallOffTilt::Endurance],
    ]);
});

it('drops the tilt from a tilted quality day the week then keeps easy', function (): void {
    $base = planInputsWith(arcInputs(today: '2026-09-21'), [
        'raceGoalTimeSec' => 3_540,
        'paces' => ['easy' => 380, 'marathon' => 330, 'threshold' => 305, 'interval' => 280],
        'longRunBaselineKm' => 14.0,
        'fallOffTilt' => FallOffTilt::Endurance,
    ]);
    $kept = app(Periodizer::class)->rowsFor($base)['2026-09-24'];
    $recovering = app(Periodizer::class)->rowsFor(planInputsWith($base, [
        'recovery' => new PostRaceRecovery(Carbon::parse('2026-09-19'), Carbon::parse('2026-09-27'), null),
    ]))['2026-09-24'];

    expect([$kept['session_type'], $kept['fall_off_tilt']])->toBe([SessionType::Tempo, FallOffTilt::Endurance])
        ->and([$recovering['session_type'], $recovering['fall_off_tilt']])->toBe([SessionType::Easy, null]);
});

it('lets a restore lapse once the week drops quality for another reason, refunding it only while its day is open', function (AdaptationReason $reason, bool $dayOpen, int $expected, bool $refunds): void {
    $inputs = planInputsWith(arcInputs(), [
        'adaptation' => ['reason' => $reason, 'deload' => false, 'quality_delta' => -1, 'adherence_pct' => 100, 'stimulus_adherence_pct' => 100],
        'qualityRestored' => true,
        'restoredDayOpen' => $dayOpen,
    ]);

    expect($inputs->currentWeekQualityDelta())->toBe($expected)
        ->and($inputs->refundsOverride())->toBe($refunds);
})->with([
    'still ran too hard' => [AdaptationReason::RanTooHard, true, 0, false],
    'now a missed stimulus, day open' => [AdaptationReason::MissedStimulus, true, -1, true],
    'now a missed stimulus, day run or past' => [AdaptationReason::MissedStimulus, false, -1, false],
]);

it('lifts only a dropped quality session back for the current week once the athlete restores it', function (int $delta, bool $restored, int $expected): void {
    $inputs = planInputsWith(arcInputs(), ['adaptation' => ['reason' => AdaptationReason::RanTooHard, 'deload' => false, 'quality_delta' => $delta, 'adherence_pct' => 100, 'stimulus_adherence_pct' => 100]]);

    expect($inputs->withQualityRestored($restored)->currentWeekQualityDelta())->toBe($expected)
        ->and($inputs->withQualityRestored($restored)->adaptation['quality_delta'])->toBe($delta);
})->with([
    'dropped, restored' => [-1, true, 0],
    'dropped, kept' => [-1, false, -1],
    'as written, restored' => [0, true, 0],
]);

it('holds the current week to its locked hard-minute cap and records what a trimmed session needed', function (): void {
    $base = planInputsWith(arcInputs(today: '2026-09-21'), [
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 12.0,
    ]);
    $free = app(Periodizer::class)->rowsFor($base);
    $currentWeek = static fn (array $rows): array => array_filter($rows, static fn (string $date): bool => $date <= '2026-09-27', ARRAY_FILTER_USE_KEY);
    $freeHard = array_sum(array_column($currentWeek($free), 'prescribed_hard_minutes'));
    $lock = intdiv($freeHard, 2);

    $rows = app(Periodizer::class)->rowsFor(planInputsWith($base, ['lockedHardCapMinutes' => $lock]));
    $trimmed = array_filter($currentWeek($rows), static fn (array $row): bool => ($row['prescription_budget'] ?? null) !== null);

    expect($freeHard)->toBeGreaterThan(0)
        ->and(array_sum(array_column($currentWeek($rows), 'prescribed_hard_minutes')))->toBeLessThanOrEqual($lock)
        ->and(array_unique(array_column($currentWeek($rows), 'week_hard_cap_minutes')))->toBe([$lock])
        ->and($rows['2026-09-28']['week_hard_cap_minutes'] ?? null)->toBe($free['2026-09-28']['week_hard_cap_minutes'] ?? null)
        ->and($trimmed)->not->toBe([]);
    foreach ($trimmed as $date => $row) {
        expect(data_get($row, 'prescription_budget.cap_minutes'))->toBe($lock)
            ->and(data_get($row, 'prescription_budget.needed_minutes'))->toBe($free[$date]['prescribed_hard_minutes'])
            ->and(data_get($row, 'prescription_budget.left_minutes'))->toBeGreaterThanOrEqual($row['prescribed_hard_minutes']);
    }
});

it('stamps an unlocked week with 30% of its planned minutes as its cap', function (): void {
    $rows = app(Periodizer::class)->rowsFor(planInputsWith(arcInputs(today: '2026-09-21'), [
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 12.0,
    ]));
    $withoutPaces = app(Periodizer::class)->rowsFor(arcInputs(today: '2026-09-21'));

    expect($rows['2026-09-21']['week_hard_cap_minutes'] ?? null)->toBeInt()->toBeGreaterThan(0)
        ->and($withoutPaces['2026-09-21']['week_hard_cap_minutes'] ?? null)->toBeNull();
});

it('gives a brought-back session its full dose outside the locked cap, leaving every other day\'s dose as the capped week had it', function (): void {
    $base = planInputsWith(arcInputs(today: '2026-09-21'), [
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 12.0,
    ]);
    $free = app(Periodizer::class)->rowsFor($base);
    $currentWeek = static fn (array $rows): array => array_filter($rows, static fn (string $date): bool => $date <= '2026-09-27', ARRAY_FILTER_USE_KEY);
    $lock = intdiv(array_sum(array_column($currentWeek($free), 'prescribed_hard_minutes')), 2);
    $locked = app(Periodizer::class)->rowsFor(planInputsWith($base, ['lockedHardCapMinutes' => $lock]));
    $trimmedDate = (string) nonNull(array_key_first(array_filter($currentWeek($locked), static fn (array $row): bool => ($row['prescription_budget'] ?? null) !== null)));

    $rows = app(Periodizer::class)->rowsFor(planInputsWith($base, ['lockedHardCapMinutes' => $lock, 'budgetOverrideDate' => $trimmedDate]));
    $others = static fn (array $rows): int => array_sum(array_column(array_diff_key($currentWeek($rows), [$trimmedDate => true]), 'prescribed_hard_minutes'));

    expect($rows[$trimmedDate]['prescribed_hard_minutes'])->toBe($free[$trimmedDate]['prescribed_hard_minutes'])
        ->and($rows[$trimmedDate]['prescription_budget'] ?? null)->toBeNull()
        ->and($others($rows))->toBe($others($locked));
});

it('withholds the budget note, and so the bring-back, from a session the hard-day spacing would demote at full dose', function (): void {
    $base = planInputsWith(arcInputs(today: '2026-10-07'), [
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 12.0,
        'actualSessions' => [['date' => '2026-10-07', 'duration_minutes' => 60, 'hard_minutes' => 40.0, 'demanding' => true]],
    ]);
    $unlocked = app(Periodizer::class)->rowsFor($base);
    $locked = app(Periodizer::class)->rowsFor(planInputsWith($base, ['lockedHardCapMinutes' => 10]));

    expect($unlocked['2026-10-08']['prescription_reason'])->toBe('easy to preserve recovery between hard days')
        ->and($locked['2026-10-08']['prescribed_hard_minutes'])->toBe(0)
        ->and($locked['2026-10-08']['prescription_budget'] ?? null)->toBeNull();
});

it('withholds the budget note, and so the bring-back, from a session whose full dose the day\'s distance cannot hold', function (): void {
    $base = planInputsWith(arcInputs(today: '2026-09-07'), [
        'paces' => ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        'longRunBaselineKm' => 8.0,
    ]);
    $unlocked = app(Periodizer::class)->rowsFor($base);
    $locked = app(Periodizer::class)->rowsFor(planInputsWith($base, ['lockedHardCapMinutes' => 0]));

    expect($unlocked['2026-09-10']['prescription_reason'])->toBe('easy because the outing cannot safely fit the minimum quality structure')
        ->and($locked['2026-09-10']['prescribed_hard_minutes'])->toBe(0)
        ->and($locked['2026-09-10']['prescription_budget'] ?? null)->toBeNull();
});
