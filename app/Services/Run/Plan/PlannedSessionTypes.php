<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use Illuminate\Support\Carbon;

final class PlannedSessionTypes
{
    /**
     * The type each such day effectively asked for, so an eased session reads as what it was eased to.
     *
     * @return array<string, SessionType> keyed by local run date
     */
    public static function byDate(int $userId, Carbon $from, Carbon $to): array
    {
        return array_map(
            EffectiveSession::settledTypeOf(...),
            self::sessionsByDate($userId, $from, $to),
        );
    }

    /**
     * The non-rest, non-excused planned session for each date on which exactly one run landed,
     * since a day with two runs cannot say which of them was the session.
     *
     * @return array<string, PlannedSession> keyed by local run date
     */
    public static function sessionsByDate(int $userId, Carbon $from, Carbon $to): array
    {
        $runCounts = [];
        $runCountsQuery = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $userId)
            ->whereBetween('activity_details.start_date_local', [$from, $to])
            ->selectRaw('DATE(activity_details.start_date_local) AS run_date, COUNT(*) AS run_count')
            ->groupBy('run_date');
        foreach (Activity::analyzedJoinConstraint($runCountsQuery)->toBase()->get() as $row) {
            $runCounts[(string) $row->run_date] = (int) $row->run_count;
        }

        $sessions = [];
        foreach (PlannedSession::query()
            ->where('user_id', $userId)
            ->where('session_type', '!=', SessionType::Rest->value)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get() as $session) {
            if ($session->isExcused()) {
                continue;
            }

            $date = $session->date->toDateString();
            if (($runCounts[$date] ?? 0) === 1) {
                $sessions[$date] = $session;
            }
        }

        return $sessions;
    }
}
