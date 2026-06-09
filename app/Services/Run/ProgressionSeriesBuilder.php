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
        return $this->buildMany($user, [$featured], fn (): ?int => $goalSec)[$featured->category->value] ?? null;
    }

    /**
     * Batch variant of build(): one query covering every requested PR's distance
     * band, bucketed in PHP, so /rekor's multi-distance selector costs a single
     * round trip instead of one per category. Keyed by PrCategory value; a
     * category with too few in-window runs is omitted. Insertion order follows
     * the given $records.
     *
     * @param  list<PersonalRecord>  $records
     * @param  callable(PersonalRecord): (int|null)  $goalResolver
     * @return array<string, array{category:string, weeks:array<int,string>, times_sec:array<int,int>, goal_sec:int|null}>
     */
    public function buildMany(User $user, array $records, callable $goalResolver): array
    {
        /** @var array<int, array{record: PersonalRecord, target: float, min: float, max: float}> $bands */
        $bands = [];
        foreach ($records as $record) {
            $target = $record->category->distanceMeters();
            if ($target === null) {
                continue;
            }
            $bands[] = [
                'record' => $record,
                'target' => $target,
                'min' => $target * (1 - self::DISTANCE_TOLERANCE),
                'max' => $target * (1 + self::DISTANCE_TOLERANCE),
            ];
        }

        if ($bands === []) {
            return [];
        }

        $since = Carbon::now()->subWeeks(self::LOOKBACK_WEEKS)->startOfWeek(Carbon::MONDAY);

        $rows = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->where(function ($q) use ($bands): void {
                foreach ($bands as $band) {
                    $q->orWhereBetween('distance', [$band['min'], $band['max']]);
                }
            })
            ->whereNotNull('moving_time')
            ->where('moving_time', '>', 0)
            ->where('start_date_local', '>=', $since)
            ->select(['start_date_local', 'moving_time', 'distance'])
            ->orderBy('start_date_local')
            ->get();

        $out = [];
        foreach ($bands as $band) {
            $inBand = $rows->whereBetween('distance', [$band['min'], $band['max']]);
            $bestByWeek = $this->bestTimePerWeek($inBand, $band['target']);
            if ($bestByWeek === []) {
                continue;
            }
            ksort($bestByWeek);
            $category = $band['record']->category;
            $out[$category->value] = [
                'category' => $category->value,
                'weeks' => array_keys($bestByWeek),
                'times_sec' => array_values($bestByWeek),
                'goal_sec' => $goalResolver($band['record']),
            ];
        }

        return $out;
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
