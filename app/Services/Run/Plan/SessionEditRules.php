<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which edits a planned day accepts, read by both
 * {@see \App\Http\Controllers\PlanController::update()} and the Plan page's
 * day props. See `docs/features/plan-periodizer.md`.
 */
final class SessionEditRules
{
    /**
     * The rows a day's rules read: its Monday-to-Sunday week plus the day
     * either side, which a hard session's adjacency check reaches.
     *
     * @return array{Carbon, Carbon}
     */
    public static function window(Carbon $date): array
    {
        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);

        return [$weekStart->copy()->subDay(), $weekStart->copy()->addDays(7)];
    }

    /**
     * @param  Collection<int, PlannedSession>  $rows  the day's {@see self::window()}
     * @param  list<string>  $ranDates  Y-m-d of every day in that window a run landed on
     * @return array{actions: array{move: bool, skip: bool, restore: bool}, move_targets: list<string>, move_warnings: array<string, array{beside: string, session_type: string}>}
     */
    public static function rulesFor(PlannedSession $day, PlannedSessionStatus $status, Collection $rows, array $ranDates, Carbon $today, ?string $overrideDate = null): array
    {
        $toggles = self::canToggleSkip($day, $status, $today);
        $moves = self::canMoveFrom($day, $status, $today) ? self::moves($day, $rows, $ranDates, $today, $overrideDate) : ['targets' => [], 'warnings' => []];

        return [
            'actions' => [
                'move' => $moves['targets'] !== [],
                'skip' => $toggles && ! $day->skipped,
                'restore' => $toggles && $day->skipped,
            ],
            'move_targets' => $moves['targets'],
            'move_warnings' => $moves['warnings'],
        ];
    }

    public static function canMoveFrom(PlannedSession $day, PlannedSessionStatus $status, Carbon $today): bool
    {
        if ($day->session_type === SessionType::Rest || $day->made_up_from_id !== null) {
            return false;
        }
        if (! $day->date->lessThan($today)) {
            return ! $status->isCredited();
        }

        return ! $day->date->lessThan($today->copy()->startOfWeek(Carbon::MONDAY))
            && ! $day->isExcused()
            && in_array($status, [PlannedSessionStatus::Planned, PlannedSessionStatus::Missed], true);
    }

    public static function canToggleSkip(PlannedSession $day, PlannedSessionStatus $status, Carbon $today): bool
    {
        return $day->session_type !== SessionType::Rest
            && $day->made_up_from_id === null
            && ! $day->date->lessThan($today)
            && ! $status->isCredited();
    }

    /**
     * @param  Collection<int, PlannedSession>  $rows  the source's {@see self::window()}
     * @param  list<string>  $ranDates
     * @return list<string>
     */
    public static function moveTargets(PlannedSession $source, Collection $rows, array $ranDates, Carbon $today, ?string $overrideDate = null): array
    {
        return self::moves($source, $rows, $ranDates, $today, $overrideDate)['targets'];
    }

    /**
     * Rest days of the session's own week from today on, and earlier ones only
     * where a run landed. From today on, an unrun session also swaps with
     * another unrun training day of its week, unless either carries the week's
     * taken override. A target that leaves a hard session beside another hard
     * day stays offered, with a warning naming it.
     *
     * @param  Collection<int, PlannedSession>  $rows  the source's {@see self::window()}
     * @param  list<string>  $ranDates
     * @return array{targets: list<string>, warnings: array<string, array{beside: string, session_type: string}>}
     */
    private static function moves(PlannedSession $source, Collection $rows, array $ranDates, Carbon $today, ?string $overrideDate): array
    {
        $sourceDate = $source->date->toDateString();
        [$windowStart, $windowEnd] = self::window($source->date);
        $weekStart = $windowStart->copy()->addDay()->toDateString();
        $weekEnd = $windowEnd->copy()->subDay()->toDateString();
        $todayKey = $today->toDateString();
        $dated = $rows->mapWithKeys(static fn (PlannedSession $row): array => [$row->date->toDateString() => $row])->all();
        $swappable = $sourceDate !== $overrideDate && self::isSwappable($source, $sourceDate, $ranDates, $todayKey);

        $targets = [];
        $warnings = [];
        foreach ($dated as $date => $row) {
            if ($date === $sourceDate || $date < $weekStart || $date > $weekEnd || $row->made_up_on !== null) {
                continue;
            }
            $offered = $row->session_type === SessionType::Rest
                ? $date >= $todayKey || in_array($date, $ranDates, true)
                : $swappable && $date !== $overrideDate && self::isSwappable($row, $date, $ranDates, $todayKey);
            if (! $offered) {
                continue;
            }
            $targets[] = $date;
            $warning = self::hardNeighbour($dated, [$date => $source->session_type, $sourceDate => $row->session_type]);
            if ($warning !== null) {
                $warnings[$date] = $warning;
            }
        }
        sort($targets);

        return ['targets' => $targets, 'warnings' => $warnings];
    }

    /**
     * @param  list<string>  $ranDates
     */
    private static function isSwappable(PlannedSession $day, string $date, array $ranDates, string $todayKey): bool
    {
        return $date >= $todayKey
            && $day->session_type !== SessionType::Race
            && $day->made_up_from_id === null
            && ! in_array($date, $ranDates, true);
    }

    /**
     * @param  array<string, PlannedSession>  $dated
     * @param  array<string, SessionType>  $swapped  the two days' types once the edit lands
     * @return array{beside: string, session_type: string}|null
     */
    private static function hardNeighbour(array $dated, array $swapped): ?array
    {
        $typeOn = static fn (string $date): ?SessionType => $swapped[$date] ?? ($dated[$date] ?? null)?->session_type;

        foreach ($swapped as $date => $type) {
            if (! self::isHard($type)) {
                continue;
            }
            $day = Carbon::parse($date);
            foreach ([$day->copy()->subDay()->toDateString(), $day->copy()->addDay()->toDateString()] as $neighbour) {
                $neighbourType = $typeOn($neighbour);
                if ($neighbourType instanceof SessionType && self::isHard($neighbourType)) {
                    return ['beside' => $neighbour, 'session_type' => $neighbourType->value];
                }
            }
        }

        return null;
    }

    /**
     * A move from a day already gone, or onto a day a run already landed on,
     * links a run to the session rather than planning one.
     *
     * @param  list<string>  $ranDates
     */
    public static function isMakeUp(PlannedSession $source, PlannedSession $target, array $ranDates, Carbon $today): bool
    {
        return $source->date->lessThan($today) || in_array($target->date->toDateString(), $ranDates, true);
    }

    private static function isHard(SessionType $type): bool
    {
        return $type->isQuality() || $type === SessionType::Race;
    }
}
