<?php

declare(strict_types=1);

use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Services\Run\Plan\SessionEditRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

const EDIT_RULES_TODAY = '2026-08-12';

function editRulesRow(string $date, SessionType $type, array $extra = []): PlannedSession
{
    return new PlannedSession()->forceFill([
        'user_id' => 1,
        'date' => $date,
        'session_type' => $type,
        'status' => PlannedSessionStatus::Planned,
        'skipped' => false,
        ...$extra,
    ]);
}

/**
 * Mon 10 Easy · Tue 11 Easy · Wed 12 (today) Easy · Thu 13 Rest · Fri 14 Easy · Sat 15 Rest · Sun 16 Long,
 * with Sun 9 and Mon 17 either side.
 *
 * @param  array<string, SessionType>  $overrides
 * @return Collection<int, PlannedSession>
 */
function editRulesWeek(array $overrides = []): Collection
{
    $types = [
        '2026-08-09' => SessionType::Rest,
        '2026-08-10' => SessionType::Easy,
        '2026-08-11' => SessionType::Easy,
        '2026-08-12' => SessionType::Easy,
        '2026-08-13' => SessionType::Rest,
        '2026-08-14' => SessionType::Easy,
        '2026-08-15' => SessionType::Rest,
        '2026-08-16' => SessionType::Long,
        '2026-08-17' => SessionType::Easy,
        ...$overrides,
    ];

    return collect($types)->map(fn (SessionType $type, string $date): PlannedSession => editRulesRow($date, $type))->values();
}

function editRulesDay(Collection $rows, string $date): PlannedSession
{
    return $rows->first(fn (PlannedSession $row): bool => $row->date->toDateString() === $date);
}

beforeEach(fn () => Carbon::setTestNow(EDIT_RULES_TODAY.' 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('loads the day either side of the Monday-to-Sunday week', function (): void {
    [$from, $to] = SessionEditRules::window(Carbon::parse('2026-08-14'));

    expect($from->toDateString())->toBe('2026-08-09')
        ->and($to->toDateString())->toBe('2026-08-17');
});

it('gives an unrun today move, skip and restore like a day still ahead', function (): void {
    $rows = editRulesWeek();
    $today = editRulesDay($rows, '2026-08-12');

    expect(SessionEditRules::actionsFor($today, PlannedSessionStatus::Planned, $rows, [], Carbon::today()))
        ->toBe(['move' => true, 'skip' => true, 'restore' => false]);

    $today->skipped = true;
    expect(SessionEditRules::actionsFor($today, PlannedSessionStatus::Planned, $rows, [], Carbon::today()))
        ->toBe(['move' => true, 'skip' => false, 'restore' => true]);
});

it('gives a today a run has credited no action at all', function (PlannedSessionStatus $status): void {
    $rows = editRulesWeek();

    expect(SessionEditRules::actionsFor(editRulesDay($rows, '2026-08-12'), $status, $rows, ['2026-08-12'], Carbon::today()))
        ->toBe(['move' => false, 'skip' => false, 'restore' => false]);
})->with([PlannedSessionStatus::Done, PlannedSessionStatus::Partial, PlannedSessionStatus::Overreached]);

it('gives an unrun past day of this week move only', function (PlannedSessionStatus $status): void {
    $rows = editRulesWeek();

    expect(SessionEditRules::actionsFor(editRulesDay($rows, '2026-08-11'), $status, $rows, [], Carbon::today()))
        ->toBe(['move' => true, 'skip' => false, 'restore' => false]);
})->with([PlannedSessionStatus::Planned, PlannedSessionStatus::Missed]);

it('keeps a credited or excused past day final', function (PlannedSessionStatus $status, bool $skipped): void {
    $rows = editRulesWeek();
    $day = editRulesDay($rows, '2026-08-11');
    $day->skipped = $skipped;

    expect(SessionEditRules::actionsFor($day, $status, $rows, [], Carbon::today()))
        ->toBe(['move' => false, 'skip' => false, 'restore' => false]);
})->with([
    'done' => [PlannedSessionStatus::Done, false],
    'partial' => [PlannedSessionStatus::Partial, false],
    'overreached' => [PlannedSessionStatus::Overreached, false],
    'skip' => [PlannedSessionStatus::Skip, true],
    'skipped, not yet scored' => [PlannedSessionStatus::Planned, true],
]);

it('never moves a day from last week', function (): void {
    $rows = editRulesWeek(['2026-08-09' => SessionType::Easy]);

    expect(SessionEditRules::actionsFor(editRulesDay($rows, '2026-08-09'), PlannedSessionStatus::Missed, $rows, [], Carbon::today()))
        ->toBe(['move' => false, 'skip' => false, 'restore' => false]);
});

it('offers nothing on a rest day', function (): void {
    $rows = editRulesWeek();

    expect(SessionEditRules::actionsFor(editRulesDay($rows, '2026-08-13'), PlannedSessionStatus::Planned, $rows, [], Carbon::today()))
        ->toBe(['move' => false, 'skip' => false, 'restore' => false]);
});

it('offers no move when no target remains', function (): void {
    $rows = editRulesWeek(['2026-08-13' => SessionType::Easy, '2026-08-15' => SessionType::Easy]);

    expect(SessionEditRules::actionsFor(editRulesDay($rows, '2026-08-14'), PlannedSessionStatus::Planned, $rows, [], Carbon::today()))
        ->toBe(['move' => false, 'skip' => true, 'restore' => false]);
});

it('targets rest days from today through Sunday, and past rest days only where a run landed', function (): void {
    $rows = editRulesWeek(['2026-08-10' => SessionType::Rest, '2026-08-11' => SessionType::Rest, '2026-08-12' => SessionType::Rest]);
    $source = editRulesDay($rows, '2026-08-14');

    expect(SessionEditRules::moveTargets($source, $rows, ['2026-08-10'], Carbon::today()))
        ->toBe(['2026-08-10', '2026-08-12', '2026-08-13', '2026-08-15']);
});

it('never targets a day outside the session\'s own week, nor a day that is not rest', function (): void {
    $rows = editRulesWeek();
    $source = editRulesDay($rows, '2026-08-11');

    expect(SessionEditRules::moveTargets($source, $rows, ['2026-08-09'], Carbon::today()))
        ->toBe(['2026-08-13', '2026-08-15']);
});

it('keeps a hard session off any day beside another hard day', function (SessionType $neighbour): void {
    $rows = editRulesWeek([
        '2026-08-11' => SessionType::Tempo,
        '2026-08-14' => $neighbour,
        '2026-08-16' => SessionType::Easy,
    ]);

    expect(SessionEditRules::moveTargets(editRulesDay($rows, '2026-08-11'), $rows, [], Carbon::today()))
        ->toBe([]);
})->with([SessionType::Long, SessionType::Interval, SessionType::Tempo, SessionType::Race]);

it('lets a hard session land beside easy days, and beside the day it leaves', function (): void {
    $rows = editRulesWeek([
        '2026-08-12' => SessionType::Tempo,
        '2026-08-16' => SessionType::Easy,
    ]);

    expect(SessionEditRules::moveTargets(editRulesDay($rows, '2026-08-12'), $rows, [], Carbon::today()))
        ->toBe(['2026-08-13', '2026-08-15']);
});

it('reads a hard neighbour across the week boundary', function (): void {
    $rows = editRulesWeek([
        '2026-08-09' => SessionType::Long,
        '2026-08-10' => SessionType::Rest,
        '2026-08-12' => SessionType::Interval,
        '2026-08-13' => SessionType::Easy,
        '2026-08-15' => SessionType::Easy,
        '2026-08-16' => SessionType::Rest,
        '2026-08-17' => SessionType::Tempo,
    ]);

    expect(SessionEditRules::moveTargets(editRulesDay($rows, '2026-08-12'), $rows, ['2026-08-10'], Carbon::today()))
        ->toBe([]);
});

it('ignores adjacency for an easy session', function (): void {
    $rows = editRulesWeek(['2026-08-14' => SessionType::Tempo]);

    expect(SessionEditRules::moveTargets(editRulesDay($rows, '2026-08-12'), $rows, [], Carbon::today()))
        ->toBe(['2026-08-13', '2026-08-15']);
});

it('moves a day still ahead within its own week, from today on', function (): void {
    $rows = collect([
        editRulesRow('2026-08-16', SessionType::Long),
        editRulesRow('2026-08-17', SessionType::Rest),
        editRulesRow('2026-08-18', SessionType::Tempo),
        editRulesRow('2026-08-19', SessionType::Rest),
        editRulesRow('2026-08-20', SessionType::Easy),
        editRulesRow('2026-08-21', SessionType::Rest),
        editRulesRow('2026-08-22', SessionType::Long),
        editRulesRow('2026-08-23', SessionType::Rest),
        editRulesRow('2026-08-24', SessionType::Rest),
    ]);

    expect(SessionEditRules::actionsFor(editRulesDay($rows, '2026-08-18'), PlannedSessionStatus::Planned, $rows, [], Carbon::today()))
        ->toBe(['move' => true, 'skip' => true, 'restore' => false])
        ->and(SessionEditRules::moveTargets(editRulesDay($rows, '2026-08-18'), $rows, [], Carbon::today()))
        ->toBe(['2026-08-19']);
});

it('never targets a day a make-up already emptied', function (): void {
    $rows = editRulesWeek();
    editRulesDay($rows, '2026-08-13')->made_up_on = Carbon::parse('2026-08-15');

    expect(SessionEditRules::moveTargets(editRulesDay($rows, '2026-08-11'), $rows, ['2026-08-13'], Carbon::today()))
        ->toBe(['2026-08-15']);
});

it('keeps a made-up session where it was linked', function (): void {
    $rows = editRulesWeek();
    $madeUp = editRulesDay($rows, '2026-08-11');
    $madeUp->made_up_from_id = 7;

    expect(SessionEditRules::actionsFor($madeUp, PlannedSessionStatus::Missed, $rows, [], Carbon::today())['move'])->toBeFalse();
});

it('gives a make-up linked onto today no skip or restore', function (bool $skipped): void {
    $rows = editRulesWeek();
    $madeUp = editRulesDay($rows, '2026-08-12');
    $madeUp->made_up_from_id = 7;
    $madeUp->skipped = $skipped;

    expect(SessionEditRules::actionsFor($madeUp, PlannedSessionStatus::Planned, $rows, [], Carbon::today()))
        ->toBe(['move' => false, 'skip' => false, 'restore' => false]);
})->with([false, true]);

it('reads a move from a past day, or onto a day a run landed on, as a make-up', function (string $source, string $target, array $ranDates, bool $makeUp): void {
    $rows = editRulesWeek(['2026-08-10' => SessionType::Rest]);

    expect(SessionEditRules::isMakeUp(editRulesDay($rows, $source), editRulesDay($rows, $target), $ranDates, Carbon::today()))
        ->toBe($makeUp);
})->with([
    'past source onto a later rest day' => ['2026-08-11', '2026-08-13', [], true],
    'today onto a rest day a run landed on' => ['2026-08-12', '2026-08-10', ['2026-08-10'], true],
    'today onto a rest day still ahead' => ['2026-08-12', '2026-08-13', [], false],
    'ahead onto a rest day still ahead' => ['2026-08-14', '2026-08-15', [], false],
]);
