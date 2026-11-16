<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\PlannedSessionStatus;
use App\Enums\PlanOverrideKind;
use App\Enums\SessionType;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Plan\QualityOverride;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-09-15 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $attributes */
function overrideDay(string $date = '2026-09-15', SessionType $type = SessionType::Interval, array $attributes = []): PlannedSession
{
    return new PlannedSession(['date' => $date, 'session_type' => $type, 'pinned' => false, 'skipped' => false, 'status' => PlannedSessionStatus::Planned, ...$attributes]);
}

/** @param  array<string, mixed>  $attributes */
function overrideWeek(array $attributes = []): PlanAdaptation
{
    return new PlanAdaptation([
        'week_start' => '2026-09-14',
        'reason' => AdaptationReason::RanTooHard,
        'deload' => false,
        'quality_delta' => -1,
        'adherence_pct' => 100,
        'stimulus_adherence_pct' => 100,
        'restorable_quality' => ['date' => '2026-09-17', 'session_type' => 'tempo', 'time_trial' => false],
        ...$attributes,
    ]);
}

/** @return array<string, mixed>|null */
function overrideFor(PlannedSession $day, ?PlanAdaptation $week = null, ?string $easeNote = null, ?ReadinessCeiling $ceiling = null, bool $ran = false, ?string $raceBlocker = null): ?array
{
    return QualityOverride::forDay($day, Carbon::today(), $day->status, $ran, $week ?? overrideWeek(), $easeNote, $ceiling, raceBlocker: $raceBlocker);
}

it('offers today\'s eased quality session back at a moderate ceiling, with the clamp\'s own reason', function (SessionType $type): void {
    expect(overrideFor(overrideDay(type: $type), easeNote: 'quality can wait.', ceiling: ReadinessCeiling::ModerateOk))
        ->toBe(['kind' => 'readiness_ease', 'state' => 'offered', 'label' => "i'm ready", 'reason' => 'quality can wait.']);
})->with([SessionType::Tempo, SessionType::Interval]);

it('offers no readiness override where the ceiling, the session or the day rules it out', function (PlannedSession $day, ?string $easeNote, ?ReadinessCeiling $ceiling, bool $ran): void {
    expect(overrideFor($day, easeNote: $easeNote, ceiling: $ceiling, ran: $ran))->toBeNull();
})->with([
    'nothing eased' => [fn () => overrideDay(), null, null, false],
    'a long run' => [fn () => overrideDay(type: SessionType::Long), 'slower.', ReadinessCeiling::ModerateOk, false],
    'already ran' => [fn () => overrideDay(), 'quality can wait.', ReadinessCeiling::ModerateOk, true],
    'skipped' => [fn () => overrideDay(attributes: ['skipped' => true]), 'quality can wait.', ReadinessCeiling::ModerateOk, false],
    'pinned' => [fn () => overrideDay(attributes: ['pinned' => true]), 'quality can wait.', ReadinessCeiling::ModerateOk, false],
    'tomorrow' => [fn () => overrideDay('2026-09-16'), 'quality can wait.', ReadinessCeiling::ModerateOk, false],
]);

it('locks today\'s eased quality session behind an easy-only or rest ceiling, naming the ceiling', function (ReadinessCeiling $ceiling, string $blocker): void {
    expect(overrideFor(overrideDay(), easeNote: 'easy today.', ceiling: $ceiling))
        ->toBe(['kind' => 'readiness_ease', 'state' => 'locked', 'blocker' => $blocker, 'reason' => 'easy today.']);
})->with([
    'easy-only ceiling' => [ReadinessCeiling::EasyOnly, 'readiness says easy only today'],
    'rest ceiling' => [ReadinessCeiling::Rest, 'readiness says rest today'],
]);

it('offers the dropped session back on its natural day by name, with the adaptation\'s own detail', function (string $today): void {
    Carbon::setTestNow($today.' 08:00:00');

    expect(overrideFor(overrideDay('2026-09-17', SessionType::Easy)))->toBe([
        'kind' => 'weekly_drop',
        'state' => 'offered',
        'label' => "bring back thursday's tempo",
        'reason' => AdaptationReason::RanTooHard->detail(100, 100, -1),
    ]);
})->with(['before the day' => '2026-09-15', 'on the day' => '2026-09-17']);

it('names a dropped time trial as a time trial', function (): void {
    $week = overrideWeek(['restorable_quality' => ['date' => '2026-09-17', 'session_type' => 'interval', 'time_trial' => true]]);

    expect(data_get(overrideFor(overrideDay('2026-09-17', SessionType::Easy), $week), 'label'))->toBe("bring back thursday's time trial");
});

it('offers no weekly drop off its natural day, once that day is gone, or on a week that dropped nothing', function (PlannedSession $day, PlanAdaptation $week, string $today): void {
    Carbon::setTestNow($today.' 08:00:00');

    expect(overrideFor($day, $week))->toBeNull();
})->with([
    'another day' => [fn () => overrideDay('2026-09-16', SessionType::Easy), fn () => overrideWeek(), '2026-09-15'],
    'the day has passed' => [fn () => overrideDay('2026-09-17', SessionType::Easy), fn () => overrideWeek(), '2026-09-18'],
    'nothing dropped' => [fn () => overrideDay('2026-09-17', SessionType::Easy), fn () => overrideWeek(['reason' => AdaptationReason::Steady, 'quality_delta' => 0, 'restorable_quality' => null]), '2026-09-15'],
    'moved by the athlete' => [fn () => overrideDay('2026-09-17', SessionType::Rest, ['pinned' => true]), fn () => overrideWeek(), '2026-09-15'],
]);

it('locks every other offer once the week\'s one override is taken, naming the day it went to', function (): void {
    $week = overrideWeek(['override_kind' => PlanOverrideKind::ReadinessEase, 'override_date' => '2026-09-14']);

    expect(overrideFor(overrideDay(), $week, 'quality can wait.', ReadinessCeiling::ModerateOk))->toBe([
        'kind' => 'readiness_ease',
        'state' => 'locked',
        'blocker' => "this week's bring-back went to monday",
        'reason' => 'quality can wait. '.QualityOverride::ONE_A_WEEK_RULE,
    ])
        ->and(overrideFor(overrideDay('2026-09-17', SessionType::Easy), $week))->toMatchArray(['kind' => 'weekly_drop', 'state' => 'locked', 'blocker' => "this week's bring-back went to monday"]);
});

it('locks the offer near the race, naming the window and the rule', function (): void {
    expect(overrideFor(overrideDay('2026-09-17', SessionType::Easy), raceBlocker: '2 weeks before race week'))->toBe([
        'kind' => 'weekly_drop',
        'state' => 'locked',
        'blocker' => '2 weeks before race week',
        'reason' => AdaptationReason::RanTooHard->detail(100, 100, -1).' '.QualityOverride::RACE_RULE,
    ]);
});

it('names how far before race week an untaken override is shut', function (?string $raceDate, ?PlanOverrideKind $taken, ?string $blocker): void {
    $race = $raceDate === null ? null : new RaceGoal()->forceFill(['race_date' => $raceDate]);

    expect(QualityOverride::raceBlocker(overrideWeek(['override_kind' => $taken]), $race))->toBe($blocker);
})->with([
    'no race' => [null, null, null],
    'race well ahead' => ['2026-10-11', null, null],
    'two weeks before race week' => ['2026-10-04', null, '2 weeks before race week'],
    'one week before race week' => ['2026-09-27', null, '1 week before race week'],
    'race week' => ['2026-09-19', null, 'race week'],
    'override already taken' => ['2026-09-19', PlanOverrideKind::WeeklyDrop, null],
]);

it('shows the week to everyone but the demo athlete', function (bool $demo): void {
    $week = overrideWeek();

    expect(QualityOverride::shownWeek(new User()->forceFill(['is_demo' => $demo]), $week))->toBe($demo ? null : $week);
})->with(['athlete' => false, 'demo athlete' => true]);

it('names the quality session a ran-too-hard week dropped from its day, even once the day has passed', function (string $today): void {
    Carbon::setTestNow($today.' 08:00:00');
    $week = overrideWeek(['restorable_quality' => null, 'dropped_quality' => ['date' => '2026-09-17', 'session_type' => 'tempo', 'time_trial' => false]]);

    expect(QualityOverride::droppedFrom(overrideDay('2026-09-17', SessionType::Easy), SessionType::Easy, $week))->toBe(['session_type' => 'tempo', 'time_trial' => false]);
})->with(['before the day' => '2026-09-15', 'after the day' => '2026-09-18']);

it('names no dropped session on another day, once it is back, or on a week that dropped nothing', function (string $date, SessionType $shown, array $week): void {
    $adaptation = overrideWeek(['dropped_quality' => ['date' => '2026-09-17', 'session_type' => 'tempo', 'time_trial' => false], ...$week]);

    expect(QualityOverride::droppedFrom(overrideDay($date, $shown), $shown, $adaptation))->toBeNull();
})->with([
    'another day' => ['2026-09-16', SessionType::Easy, []],
    'brought back' => ['2026-09-17', SessionType::Tempo, []],
    'week no longer ran too hard' => ['2026-09-17', SessionType::Easy, ['reason' => AdaptationReason::Steady, 'quality_delta' => 0]],
    'nothing recorded' => ['2026-09-17', SessionType::Easy, ['dropped_quality' => null]],
]);

it('marks the day the athlete chose to run, undoable until a run lands on it', function (PlanOverrideKind $kind, string $today, bool $ran, bool $undoable): void {
    Carbon::setTestNow($today.' 08:00:00');
    $week = overrideWeek(['override_kind' => $kind, 'override_date' => '2026-09-17']);

    expect(overrideFor(overrideDay('2026-09-17'), $week, ran: $ran))
        ->toBe(['kind' => $kind->value, 'state' => 'taken', 'note' => QualityOverride::TAKEN_NOTE, 'undoable' => $undoable]);
})->with([
    'weekly drop ahead' => [PlanOverrideKind::WeeklyDrop, '2026-09-15', false, true],
    'readiness on the day' => [PlanOverrideKind::ReadinessEase, '2026-09-17', false, true],
    'after a run' => [PlanOverrideKind::ReadinessEase, '2026-09-17', true, false],
    'the day has passed' => [PlanOverrideKind::WeeklyDrop, '2026-09-18', false, false],
]);

it('moves the taken note to the day an overridden session was made up on, with no undo', function (): void {
    Carbon::setTestNow('2026-09-16 08:00:00');
    $week = overrideWeek(['override_kind' => PlanOverrideKind::ReadinessEase, 'override_date' => '2026-09-15']);
    $emptied = overrideDay('2026-09-15', SessionType::Rest, ['made_up_on' => '2026-09-17']);
    $madeUp = overrideDay('2026-09-17', SessionType::Interval, ['made_up_from_id' => 7]);
    $takenOn = QualityOverride::takenOn($week, collect([$emptied, overrideDay('2026-09-16', SessionType::Easy), $madeUp]));

    expect($takenOn)->toBe('2026-09-17')
        ->and(QualityOverride::forDay($emptied, Carbon::today(), $emptied->status, false, $week, null, null, $takenOn))->toBeNull()
        ->and(QualityOverride::forDay($madeUp, Carbon::today(), $madeUp->status, false, $week, null, null, $takenOn))
        ->toBe(['kind' => 'readiness_ease', 'state' => 'taken', 'note' => QualityOverride::TAKEN_NOTE, 'undoable' => false]);
});

it('keeps the taken note on the override date while its day was not made up', function (?PlanAdaptation $week, ?string $takenOn): void {
    expect(QualityOverride::takenOn($week, collect([overrideDay('2026-09-17')])))->toBe($takenOn);
})->with([
    'taken' => [fn (): PlanAdaptation => overrideWeek(['override_kind' => PlanOverrideKind::WeeklyDrop, 'override_date' => '2026-09-17']), '2026-09-17'],
    'none taken' => [fn (): PlanAdaptation => overrideWeek(), null],
    'no week' => [null, null],
]);

it('offers nothing outside the week the adaptation covers', function (): void {
    expect(overrideFor(overrideDay('2026-09-22', SessionType::Easy), overrideWeek(['restorable_quality' => ['date' => '2026-09-22', 'session_type' => 'tempo', 'time_trial' => false]])))->toBeNull();
});

it('opens the override to everyone but the demo athlete, outside the weeks before race week', function (bool $demo, ?string $raceDate, bool $open): void {
    $user = new User()->forceFill(['is_demo' => $demo]);
    $race = $raceDate === null ? null : new RaceGoal()->forceFill(['race_date' => $raceDate]);
    $week = overrideWeek();

    expect(QualityOverride::openWeek($user, $week, $race))->toBe($open ? $week : null);
})->with([
    'no race' => [false, null, true],
    'race well ahead' => [false, '2026-10-11', true],
    'two weeks before race week' => [false, '2026-10-04', false],
    'demo athlete' => [true, null, false],
]);

it('keeps a taken override, its note and its undo once the week falls before race week, locking anything new there', function (): void {
    $user = new User()->forceFill(['is_demo' => false]);
    $race = new RaceGoal()->forceFill(['race_date' => '2026-10-04']);
    $week = overrideWeek(['override_kind' => PlanOverrideKind::WeeklyDrop, 'override_date' => '2026-09-17']);

    $open = QualityOverride::openWeek($user, $week, $race);

    expect($open)->toBe($week)
        ->and(overrideFor(overrideDay('2026-09-17'), $open))->toBe(['kind' => 'weekly_drop', 'state' => 'taken', 'note' => QualityOverride::TAKEN_NOTE, 'undoable' => true])
        ->and(overrideFor(overrideDay(), $open, 'quality can wait.', ReadinessCeiling::ModerateOk, raceBlocker: QualityOverride::raceBlocker($open, $race)))
        ->toMatchArray(['state' => 'locked', 'blocker' => "this week's bring-back went to thursday"]);
});

it('offers a session the hard-minute budget took back, stating how far over the cap it puts the week', function (): void {
    $day = overrideDay('2026-09-20', SessionType::Long, ['prescription_budget' => ['cap_minutes' => 60, 'left_minutes' => 0, 'needed_minutes' => 15]]);

    expect(overrideFor($day, overrideWeek(['reason' => AdaptationReason::Steady, 'quality_delta' => 0, 'restorable_quality' => null])))->toBe([
        'kind' => 'budget_drop',
        'state' => 'offered',
        'label' => "bring it back · 15 hard min over this week's cap",
        'reason' => 'this week has 0 of its 60 hard minutes left, and this needs 15.',
    ]);
});

it('offers no budget bring-back on a day already run or gone', function (string $date, bool $ran): void {
    $day = overrideDay($date, SessionType::Long, ['prescription_budget' => ['cap_minutes' => 60, 'left_minutes' => 5, 'needed_minutes' => 15]]);

    expect(overrideFor($day, ran: $ran))->toBeNull();
})->with([
    'yesterday' => ['2026-09-14', false],
    'already ran' => ['2026-09-15', true],
]);

it('offers a session the weekly hard-day count made easy back as a third hard day', function (): void {
    $day = overrideDay('2026-09-20', SessionType::Long, ['hard_day_restorable' => true]);

    expect(overrideFor($day, overrideWeek(['reason' => AdaptationReason::Steady, 'quality_delta' => 0, 'restorable_quality' => null])))->toBe([
        'kind' => 'hard_day_drop',
        'state' => 'offered',
        'label' => 'bring it back · a third hard day this week',
        'reason' => 'this week already has two hard days, so the plan made this one easy.',
    ]);
});

it('offers no hard-day bring-back on a day already run or gone', function (string $date, bool $ran): void {
    $day = overrideDay($date, SessionType::Long, ['hard_day_restorable' => true]);

    expect(overrideFor($day, ran: $ran))->toBeNull();
})->with([
    'yesterday' => ['2026-09-14', false],
    'already ran' => ['2026-09-15', true],
]);

it('locks a hard-day bring-back once the week\'s override is taken', function (): void {
    $day = overrideDay('2026-09-20', SessionType::Long, ['hard_day_restorable' => true]);
    $week = overrideWeek(['reason' => AdaptationReason::Steady, 'quality_delta' => 0, 'restorable_quality' => null, 'override_kind' => PlanOverrideKind::BudgetDrop, 'override_date' => '2026-09-18']);

    expect(overrideFor($day, $week))->toMatchArray(['kind' => 'hard_day_drop', 'state' => 'locked', 'blocker' => "this week's bring-back went to friday"]);
});
