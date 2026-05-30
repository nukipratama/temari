<?php

declare(strict_types=1);

namespace App\Services\Run;

use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Builds the weekly-best progression chart for the featured PR on /rekor.
 *
 * For the featured PR's distance bucket (target distance +/- 5%), it finds the
 * best moving_time per ISO week over the last 26 weeks, scaling each run's time
 * to the exact target distance so a 9.7km run and a 10.2km run compare
 * apples-to-apples. The goal line is the milestone target seconds supplied by
 * the caller.
 */
class ProgressionSeriesBuilder
{
    private const int LOOKBACK_WEEKS = 26;

    private const float DISTANCE_TOLERANCE = 0.05;

    /**
     * @return array{category:string, weeks:array<int,string>, times_sec:array<int,int>, goal_sec:int|null}|null
     */
    public function build(User $user, PersonalRecord $featured, ?int $goalSec): ?array
    {
        $target = $featured->category->distanceMeters();
        if ($target === null) {
            return null;
        }

        $minDist = $target * (1 - self::DISTANCE_TOLERANCE);
        $maxDist = $target * (1 + self::DISTANCE_TOLERANCE);
        $since = Carbon::now()->subWeeks(self::LOOKBACK_WEEKS)->startOfWeek(Carbon::MONDAY);

        $rows = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id)->whereNotNull('analyzed_at'))
            ->whereBetween('distance', [$minDist, $maxDist])
            ->whereNotNull('moving_time')
            ->where('moving_time', '>', 0)
            ->where('start_date_local', '>=', $since)
            ->select(['start_date_local', 'moving_time', 'distance'])
            ->orderBy('start_date_local')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $bestByWeek = $this->bestTimePerWeek($rows, $target);

        if ($bestByWeek === []) {
            return null;
        }

        ksort($bestByWeek);

        return [
            'category' => $featured->category->value,
            'weeks' => array_keys($bestByWeek),
            'times_sec' => array_values($bestByWeek),
            'goal_sec' => $goalSec,
        ];
    }

    /**
     * @param  Collection<int, ActivityDetail>  $rows
     * @return array<string, int>
     */
    private function bestTimePerWeek(Collection $rows, float $target): array
    {
        /** @var array<string, int> $bestByWeek */
        $bestByWeek = [];
        foreach ($rows as $row) {
            if ($row->start_date_local === null) {
                continue;
            }
            $weekKey = Carbon::parse($row->start_date_local)->startOfWeek(Carbon::MONDAY)->toDateString();
            // Scale the moving_time to the exact target distance so weeks with
            // a 9.7km run and a 10.2km run compare apples-to-apples.
            $scaled = (int) round((int) $row->moving_time * ($target / (float) $row->distance));
            if (! isset($bestByWeek[$weekKey]) || $scaled < $bestByWeek[$weekKey]) {
                $bestByWeek[$weekKey] = $scaled;
            }
        }

        return $bestByWeek;
    }
}
