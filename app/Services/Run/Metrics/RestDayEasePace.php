<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Story\PastYouMatcher;
use Illuminate\Support\Carbon;

/**
 * Home's rest-day fact: whether this athlete's easy runs land quicker or
 * slower the day after a calendar day with no run at all, against every
 * other easy run in the same trailing window. Deterministic, no LLM.
 */
class RestDayEasePace
{
    public const int WINDOW_WEEKS = 12;

    public const int MIN_SAMPLES = 5;

    public function __construct(
        private readonly PastYouMatcher $matcher,
    ) {
    }

    /**
     * @return array{deltaSecPerKm: float, direction: 'quicker'|'slower'}|null
     */
    public function forUser(User $user, ?Carbon $asOf = null): ?array
    {
        $anchor = ($asOf ?? Carbon::today())->copy()->endOfDay();
        $windowStart = $anchor->copy()->subWeeks(self::WINDOW_WEEKS)->startOfDay();

        // One extra day back covers the case where an easy run lands on the
        // window's first day: its previous calendar day sits just outside it.
        $runDates = $this->runDates($user->id, $windowStart->copy()->subDay(), $anchor);
        $easyPacesByDate = $this->easyPacesByDate($user->id, $windowStart, $anchor);

        $afterRest = [];
        $others = [];
        foreach ($easyPacesByDate as $date => $paces) {
            $previousDay = Carbon::parse($date)->subDay()->toDateString();
            $isAfterRest = ! isset($runDates[$previousDay]);
            foreach ($paces as $pace) {
                if ($isAfterRest) {
                    $afterRest[] = $pace;
                } else {
                    $others[] = $pace;
                }
            }
        }

        if (count($afterRest) < self::MIN_SAMPLES || count($others) < self::MIN_SAMPLES) {
            return null;
        }

        $meanAfterRest = array_sum($afterRest) / count($afterRest);
        $meanOthers = array_sum($others) / count($others);
        $deltaSec = $meanOthers - $meanAfterRest;

        return [
            'deltaSecPerKm' => round(abs($deltaSec), 1),
            'direction' => $deltaSec > 0 ? 'quicker' : 'slower',
        ];
    }

    /**
     * Every calendar day in the range this athlete logged at least one run,
     * regardless of pace band — what a rest day is measured against.
     *
     * @return array<string, true>
     */
    private function runDates(int $userId, Carbon $from, Carbon $to): array
    {
        $rows = Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local')
            ->where('activity_details.start_date_local', '>=', $from)
            ->where('activity_details.start_date_local', '<=', $to)
            ->toBase()
            ->pluck('activity_details.start_date_local');

        $dates = [];
        foreach ($rows as $row) {
            $dates[Carbon::parse((string) $row)->toDateString()] = true;
        }

        return $dates;
    }

    /**
     * Easy-band pace (sec/km, distance over moving time) for every run in the
     * window, keyed by calendar day.
     *
     * @return array<string, list<float>>
     */
    private function easyPacesByDate(int $userId, Carbon $from, Carbon $to): array
    {
        $rows = Activity::analyzedJoinConstraint(
            ActivityDetail::query()
                ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
                ->select([
                    'activity_details.start_date_local',
                    'activity_details.distance',
                    'activity_details.moving_time',
                ]),
        )
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local')
            ->where('activity_details.start_date_local', '>=', $from)
            ->where('activity_details.start_date_local', '<=', $to)
            ->toBase()
            ->get();

        $byDate = [];
        foreach ($rows as $row) {
            if ($row->distance === null || $row->moving_time === null) {
                continue;
            }

            $pace = PaceCalculator::secPerKm((float) $row->distance, (int) $row->moving_time);
            if ($pace === null || $this->matcher->paceBand($pace) !== PastYouMatcher::BAND_EASY) {
                continue;
            }

            $date = Carbon::parse((string) $row->start_date_local)->toDateString();
            $byDate[$date][] = $pace;
        }

        return $byDate;
    }
}
