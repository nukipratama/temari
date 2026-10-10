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
     * @return array{actions: array{move: bool, skip: bool, restore: bool}, move_targets: list<string>}
     */
    public static function rulesFor(PlannedSession $day, PlannedSessionStatus $status, Collection $rows, array $ranDates, Carbon $today): array
    {
        $toggles = self::canToggleSkip($day, $status, $today);
        $moveTargets = self::canMoveFrom($day, $status, $today) ? self::moveTargets($day, $rows, $ranDates, $today) : [];

        return [
            'actions' => [
                'move' => $moveTargets !== [],
                'skip' => $toggles && ! $day->skipped,
                'restore' => $toggles && $day->skipped,
            ],
            'move_targets' => $moveTargets,
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
     * Rest days of the session's own week from today on, and earlier ones only
     * where a run landed. A hard session never lands beside another hard day.
     *
     * @param  Collection<int, PlannedSession>  $rows  the source's {@see self::window()}
     * @param  list<string>  $ranDates
     * @return list<string>
     */
    public static function moveTargets(PlannedSession $source, Collection $rows, array $ranDates, Carbon $today): array
    {
        $sourceDate = $source->date->toDateString();
        [$windowStart, $windowEnd] = self::window($source->date);
        $weekStart = $windowStart->copy()->addDay()->toDateString();
        $weekEnd = $windowEnd->copy()->subDay()->toDateString();
        $todayKey = $today->toDateString();
        $dated = $rows->map(static fn (PlannedSession $row): array => [$row->date->toDateString(), $row])->all();
        $besideHard = [];
        if (self::isHard($source->session_type)) {
            foreach ($dated as [$date, $row]) {
                if ($date !== $sourceDate && self::isHard($row->session_type)) {
                    $day = Carbon::parse($date);
                    $besideHard[$day->copy()->subDay()->toDateString()] = true;
                    $besideHard[$day->addDay()->toDateString()] = true;
                }
            }
        }

        $targets = [];
        foreach ($dated as [$date, $row]) {
            if ($date === $sourceDate || $date < $weekStart || $date > $weekEnd || $row->session_type !== SessionType::Rest || $row->made_up_on !== null) {
                continue;
            }
            if (($date < $todayKey && ! in_array($date, $ranDates, true)) || isset($besideHard[$date])) {
                continue;
            }
            $targets[] = $date;
        }
        sort($targets);

        return $targets;
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
