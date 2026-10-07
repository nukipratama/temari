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
     * @return array{move: bool, skip: bool, restore: bool}
     */
    public static function actionsFor(PlannedSession $day, PlannedSessionStatus $status, Collection $rows, array $ranDates, Carbon $today): array
    {
        $toggles = self::canToggleSkip($day, $status, $today);

        return [
            'move' => self::canMoveFrom($day, $status, $today) && self::moveTargets($day, $rows, $ranDates, $today) !== [],
            'skip' => $toggles && ! $day->skipped,
            'restore' => $toggles && $day->skipped,
        ];
    }

    public static function canMoveFrom(PlannedSession $day, PlannedSessionStatus $status, Carbon $today): bool
    {
        if ($day->session_type === SessionType::Rest) {
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
        $hardDates = $rows
            ->filter(static fn (PlannedSession $row): bool => self::isHard($row->session_type) && $row->date->toDateString() !== $sourceDate)
            ->map(static fn (PlannedSession $row): string => $row->date->toDateString())
            ->all();
        $todayKey = $today->toDateString();

        return array_values($rows
            ->filter(static function (PlannedSession $row) use ($source, $sourceDate, $weekStart, $weekEnd, $hardDates, $ranDates, $todayKey): bool {
                $date = $row->date->toDateString();
                if ($date === $sourceDate || $date < $weekStart || $date > $weekEnd || $row->session_type !== SessionType::Rest) {
                    return false;
                }
                if ($date < $todayKey && ! in_array($date, $ranDates, true)) {
                    return false;
                }

                return ! self::isHard($source->session_type)
                    || array_intersect([$row->date->copy()->subDay()->toDateString(), $row->date->copy()->addDay()->toDateString()], $hardDates) === [];
            })
            ->map(static fn (PlannedSession $row): string => $row->date->toDateString())
            ->sort()
            ->all());
    }

    private static function isHard(SessionType $type): bool
    {
        return $type->isQuality() || $type === SessionType::Race;
    }
}
