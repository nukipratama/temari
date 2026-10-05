<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\PlanPhase;
use App\Services\Run\Plan\PlanInputs;
use App\Services\Run\Plan\TimeTrialSchedule;
use Illuminate\Support\Carbon;

/**
 * Race on Saturday 2026-12-19, race week from 2026-12-14; the season starts on 2026-08-03.
 *
 * @param  list<array{date: string, retry: bool, skipped: bool}>  $trials
 * @param  list<string>  $evidence
 */
function trialScheduleInputs(bool $race = true, array $trials = [], array $evidence = [], ?int $aim = 1_500): PlanInputs
{
    return new PlanInputs(
        userId: 1,
        today: Carbon::parse('2026-08-03'),
        seasonStart: Carbon::parse('2026-08-03'),
        seasonEnd: Carbon::parse($race ? '2026-12-19' : '2026-10-25'),
        recovery: null,
        raceDate: $race ? Carbon::parse('2026-12-19') : null,
        raceDistanceM: $race ? 10_000.0 : null,
        sessionsPerWeek: 4,
        runDays: null,
        longRunDay: null,
        adaptation: ['reason' => AdaptationReason::Steady, 'deload' => false, 'quality_delta' => 0, 'adherence_pct' => 100, 'stimulus_adherence_pct' => 100],
        pinnedDates: [],
        settledDates: [],
        projectedRaceSeconds: null,
        timeTrialAimSec: $aim,
        timeTrials: $trials,
        timeTrialEvidenceDates: $evidence,
    );
}

/** @return array<string, bool> week start => whether a trial is offered, in order */
function offeredWeeks(TimeTrialSchedule $schedule, array $weeks, array $deloads = []): array
{
    $offered = [];
    foreach ($weeks as $week) {
        $trial = $schedule->forWeek(Carbon::parse($week), in_array($week, $deloads, true) ? PlanPhase::Deload : PlanPhase::Build);
        $offered[$week] = $trial === null ? 'none' : ($trial->retry ? 'retry' : 'trial');
        if ($trial !== null) {
            $schedule->placedIn(Carbon::parse($week));
        }
    }

    return $offered;
}

it('counts a race season back from race week, the last four weeks out, then every six', function (): void {
    expect(TimeTrialSchedule::dueWeeks(trialScheduleInputs()))->toBe(['2026-08-24', '2026-10-05', '2026-11-16']);
});

it('counts a season with no race forward from its third week, then every six', function (): void {
    expect(TimeTrialSchedule::dueWeeks(trialScheduleInputs(race: false)))->toBe(['2026-08-17', '2026-09-28']);
});

it('never offers a trial in the last three weeks before the race', function (): void {
    $schedule = new TimeTrialSchedule(trialScheduleInputs(trials: [['date' => '2026-11-17', 'retry' => false, 'skipped' => true]]));

    expect(offeredWeeks($schedule, ['2026-11-23', '2026-11-30', '2026-12-07', '2026-12-14']))
        ->toBe(['2026-11-23' => 'none', '2026-11-30' => 'none', '2026-12-07' => 'none', '2026-12-14' => 'none']);
});

it('offers one trial per cycle, in its due week', function (): void {
    $schedule = new TimeTrialSchedule(trialScheduleInputs());

    expect(offeredWeeks($schedule, ['2026-08-17', '2026-08-24', '2026-08-31', '2026-09-28', '2026-10-05', '2026-10-12']))
        ->toBe(['2026-08-17' => 'none', '2026-08-24' => 'trial', '2026-08-31' => 'none', '2026-09-28' => 'none', '2026-10-05' => 'trial', '2026-10-12' => 'none']);
});

it('moves a trial due in a deload week to the next week of its cycle, without spending the retry', function (): void {
    $schedule = new TimeTrialSchedule(trialScheduleInputs());

    expect(offeredWeeks($schedule, ['2026-08-24', '2026-08-31', '2026-09-07'], deloads: ['2026-08-24']))
        ->toBe(['2026-08-24' => 'none', '2026-08-31' => 'trial', '2026-09-07' => 'none']);
});

it('keeps a trial pending through a week that could not hold it', function (): void {
    $schedule = new TimeTrialSchedule(trialScheduleInputs());

    expect($schedule->forWeek(Carbon::parse('2026-08-24'), PlanPhase::Build)?->retry)->toBeFalse()
        ->and($schedule->forWeek(Carbon::parse('2026-08-31'), PlanPhase::Build)?->retry)->toBeFalse();
});

it('skips a trial when evidence at its distance landed in the four weeks before it', function (string $evidenceOn, string $offered): void {
    $schedule = new TimeTrialSchedule(trialScheduleInputs(evidence: [$evidenceOn]));

    expect(offeredWeeks($schedule, ['2026-08-24', '2026-08-31'])['2026-08-24'])->toBe($offered);
})->with([
    'three weeks before' => ['2026-08-03', 'none'],
    'exactly four weeks before' => ['2026-07-27', 'none'],
    'more than four weeks before' => ['2026-07-26', 'trial'],
]);

it('keeps a skip for evidence through the rest of its cycle', function (): void {
    $schedule = new TimeTrialSchedule(trialScheduleInputs(evidence: ['2026-08-05']));

    expect(offeredWeeks($schedule, ['2026-08-24', '2026-08-31', '2026-09-07']))
        ->toBe(['2026-08-24' => 'none', '2026-08-31' => 'none', '2026-09-07' => 'none']);
});

it('offers a skipped trial once more the following week, and never a second time', function (): void {
    $skipped = new TimeTrialSchedule(trialScheduleInputs(trials: [['date' => '2026-08-25', 'retry' => false, 'skipped' => true]]));
    $retrySkipped = new TimeTrialSchedule(trialScheduleInputs(trials: [
        ['date' => '2026-08-25', 'retry' => false, 'skipped' => true],
        ['date' => '2026-09-01', 'retry' => true, 'skipped' => true],
    ]));
    $run = new TimeTrialSchedule(trialScheduleInputs(trials: [['date' => '2026-08-25', 'retry' => false, 'skipped' => false]]));

    expect(offeredWeeks($skipped, ['2026-08-31', '2026-09-07']))->toBe(['2026-08-31' => 'retry', '2026-09-07' => 'none'])
        ->and(offeredWeeks($retrySkipped, ['2026-09-07', '2026-09-14']))->toBe(['2026-09-07' => 'none', '2026-09-14' => 'none'])
        ->and(offeredWeeks($run, ['2026-08-31']))->toBe(['2026-08-31' => 'none']);
});

it('drops the retry when the following week is a deload, inside the last three weeks, or covered by fresh evidence', function (PlanInputs $inputs, string $week, bool $deload): void {
    expect(new TimeTrialSchedule($inputs)->forWeek(Carbon::parse($week), $deload ? PlanPhase::Deload : PlanPhase::Build))->toBeNull();
})->with([
    'deload' => [fn (): PlanInputs => trialScheduleInputs(trials: [['date' => '2026-08-25', 'retry' => false, 'skipped' => true]]), '2026-08-31', true],
    'taper' => [fn (): PlanInputs => trialScheduleInputs(trials: [['date' => '2026-11-17', 'retry' => false, 'skipped' => true]]), '2026-11-23', false],
    'fresh evidence' => [fn (): PlanInputs => trialScheduleInputs(trials: [['date' => '2026-08-25', 'retry' => false, 'skipped' => true]], evidence: ['2026-08-27']), '2026-08-31', false],
]);

it('offers nothing without a supported time to aim around', function (): void {
    expect(new TimeTrialSchedule(trialScheduleInputs(aim: null))->forWeek(Carbon::parse('2026-08-24'), PlanPhase::Build))->toBeNull();
});

it('lets a new cycle\'s trial take the week a retry would have used', function (): void {
    $schedule = new TimeTrialSchedule(trialScheduleInputs(trials: [['date' => '2026-09-29', 'retry' => false, 'skipped' => true]]));

    expect($schedule->forWeek(Carbon::parse('2026-10-05'), PlanPhase::Build)?->retry)->toBeFalse();
});
