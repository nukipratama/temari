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
function overrideFor(PlannedSession $day, ?PlanAdaptation $week = null, ?string $easeNote = null, ?ReadinessCeiling $ceiling = null, bool $ran = false): ?array
{
    return QualityOverride::forDay($day, Carbon::today(), $day->status, $ran, $week ?? overrideWeek(), $easeNote, $ceiling);
}

it('offers today\'s eased quality session back at a moderate ceiling, with the clamp\'s own reason', function (SessionType $type): void {
    expect(overrideFor(overrideDay(type: $type), easeNote: 'quality can wait.', ceiling: ReadinessCeiling::ModerateOk))
        ->toBe(['kind' => 'readiness_ease', 'state' => 'offered', 'label' => "i'm ready", 'reason' => 'quality can wait.']);
})->with([SessionType::Tempo, SessionType::Interval]);

it('offers no readiness override where the ceiling, the session or the day rules it out', function (PlannedSession $day, ?string $easeNote, ?ReadinessCeiling $ceiling, bool $ran): void {
    expect(overrideFor($day, easeNote: $easeNote, ceiling: $ceiling, ran: $ran))->toBeNull();
})->with([
    'easy-only ceiling' => [fn () => overrideDay(), 'easy today.', ReadinessCeiling::EasyOnly, false],
    'rest ceiling' => [fn () => overrideDay(), 'rest today.', ReadinessCeiling::Rest, false],
    'nothing eased' => [fn () => overrideDay(), null, null, false],
    'a long run' => [fn () => overrideDay(type: SessionType::Long), 'slower.', ReadinessCeiling::ModerateOk, false],
    'already ran' => [fn () => overrideDay(), 'quality can wait.', ReadinessCeiling::ModerateOk, true],
    'skipped' => [fn () => overrideDay(attributes: ['skipped' => true]), 'quality can wait.', ReadinessCeiling::ModerateOk, false],
    'pinned' => [fn () => overrideDay(attributes: ['pinned' => true]), 'quality can wait.', ReadinessCeiling::ModerateOk, false],
    'tomorrow' => [fn () => overrideDay('2026-09-16'), 'quality can wait.', ReadinessCeiling::ModerateOk, false],
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

it('offers nothing more once the week\'s one override is taken', function (): void {
    $week = overrideWeek(['override_kind' => PlanOverrideKind::ReadinessEase, 'override_date' => '2026-09-14']);

    expect(overrideFor(overrideDay(), $week, 'quality can wait.', ReadinessCeiling::ModerateOk))->toBeNull()
        ->and(overrideFor(overrideDay('2026-09-17', SessionType::Easy), $week))->toBeNull();
});

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

it('keeps a taken override, its note and its undo once the week falls before race week, offering nothing new there', function (): void {
    $user = new User()->forceFill(['is_demo' => false]);
    $race = new RaceGoal()->forceFill(['race_date' => '2026-10-04']);
    $week = overrideWeek(['override_kind' => PlanOverrideKind::WeeklyDrop, 'override_date' => '2026-09-17']);

    $open = QualityOverride::openWeek($user, $week, $race);

    expect($open)->toBe($week)
        ->and(overrideFor(overrideDay('2026-09-17'), $open))->toBe(['kind' => 'weekly_drop', 'state' => 'taken', 'note' => QualityOverride::TAKEN_NOTE, 'undoable' => true])
        ->and(overrideFor(overrideDay(), $open, 'quality can wait.', ReadinessCeiling::ModerateOk))->toBeNull();
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
