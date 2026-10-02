<?php

declare(strict_types=1);

namespace App\Services\Run;

use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Builds the weekly-best progression chart for the featured PR on /records.
 *
 * For the featured PR's distance bucket (target distance +/- 5%), it finds the
 * best elapsed_time per ISO week over the last 26 weeks, scaling each run's time
 * to the exact target distance so a 9.7km run and a 10.2km run compare
 * apples-to-apples. The goal line is the milestone target seconds supplied by
 * the caller.
 */
class ProgressionSeriesBuilder
{
    private const int LOOKBACK_WEEKS = 26;

    private const float DISTANCE_TOLERANCE = 0.05;

    private const int WINDOW_WEEKS = 4;

    private const float FLAT_SHARE = 0.01;

    public const string FASTER = 'faster';

    public const string SLOWER = 'slower';

    public const string FLAT = 'flat';

    /**
     * @return array{category:string, weeks:array<int,string>, times_sec:array<int,int>, activity_ids:array<int,int|null>, goal_sec:int|null, progress:array{relation:string, delta_sec:int, from_sec:int, to_sec:int, weeks:int}|null}|null
     */
    public function build(User $user, PersonalRecord $featured, ?int $goalSec): ?array
    {
        return $this->buildMany($user, [$featured], fn (): ?int => $goalSec)[$featured->category->value] ?? null;
    }

    /**
     * Batch variant of build(): one query covering every requested PR's distance
     * band, bucketed in PHP, so /records's multi-distance selector costs a single
     * round trip instead of one per category. Keyed by PrCategory value; a
     * category with too few in-window runs is omitted. Insertion order follows
     * the given $records.
     *
     * @param  list<PersonalRecord>  $records
     * @param  callable(PersonalRecord): (int|null)  $goalResolver
     * @return array<string, array{category:string, weeks:array<int,string>, times_sec:array<int,int>, activity_ids:array<int,int|null>, goal_sec:int|null, progress:array{relation:string, delta_sec:int, from_sec:int, to_sec:int, weeks:int}|null}>
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
            ->whereNotNull('elapsed_time')
            ->where('elapsed_time', '>', 0)
            ->where('start_date_local', '>=', $since)
            ->select(['activity_id', 'start_date_local', 'elapsed_time', 'distance'])
            ->orderBy('start_date_local')
            ->get();

        $out = [];
        foreach ($bands as $band) {
            $inBand = $rows->whereBetween('distance', [$band['min'], $band['max']]);
            $bestByWeek = $this->bestTimePerWeek($inBand, $band['target']);
            if ($bestByWeek === []) {
                continue;
            }
            $this->snapBestToRecord($bestByWeek, $band['record']);
            ksort($bestByWeek);
            $category = $band['record']->category;
            $out[$category->value] = [
                'category' => $category->value,
                'weeks' => array_keys($bestByWeek),
                'times_sec' => array_map(fn (array $row): int => $row['time'], array_values($bestByWeek)),
                'activity_ids' => array_map(fn (array $row): ?int => $row['activity_id'], array_values($bestByWeek)),
                'goal_sec' => $goalResolver($band['record']),
                'progress' => $this->progress($bestByWeek, $since),
            ];
        }

        return $out;
    }

    /**
     * The best of the lookback's first four weeks against the best of its last
     * four, as a relation word beside an unsigned delta. Null when either
     * window has no run.
     *
     * @param  array<string, array{time:int, activity_id:int|null}>  $bestByWeek
     * @return array{relation:string, delta_sec:int, from_sec:int, to_sec:int, weeks:int}|null
     */
    private function progress(array $bestByWeek, Carbon $since): ?array
    {
        $earliestEnd = $since->copy()->addWeeks(self::WINDOW_WEEKS)->toDateString();
        $latestStart = Carbon::now()->startOfWeek(Carbon::MONDAY)->subWeeks(self::WINDOW_WEEKS - 1)->toDateString();

        $from = $this->windowBest(array_filter($bestByWeek, fn (string $week): bool => $week < $earliestEnd, ARRAY_FILTER_USE_KEY));
        $to = $this->windowBest(array_filter($bestByWeek, fn (string $week): bool => $week >= $latestStart, ARRAY_FILTER_USE_KEY));
        if ($from === null || $to === null) {
            return null;
        }

        $saved = $from['time'] - $to['time'];
        $relation = match (true) {
            abs($saved) < $from['time'] * self::FLAT_SHARE => self::FLAT,
            $saved > 0 => self::FASTER,
            default => self::SLOWER,
        };

        return [
            'relation' => $relation,
            'delta_sec' => abs($saved),
            'from_sec' => $from['time'],
            'to_sec' => $to['time'],
            'weeks' => (int) Carbon::parse($from['week'])->diffInWeeks(Carbon::parse($to['week'])),
        ];
    }

    /**
     * @param  array<string, array{time:int, activity_id:int|null}>  $window
     * @return array{week:string, time:int}|null
     */
    private function windowBest(array $window): ?array
    {
        $best = null;
        foreach ($window as $week => $row) {
            if ($best === null || $row['time'] < $best['time']) {
                $best = ['week' => $week, 'time' => $row['time']];
            }
        }

        return $best;
    }

    /**
     * Overwrite the PR's own week with its stored time, so the chart's labeled
     * best point matches the /records hero and trophy wall exactly instead of
     * drifting a second or two (the weekly series scales whole-run elapsed_time
     * while the PR is split-interpolated to the exact target distance).
     *
     * Only the PR's own ISO week is snapped: if the PR was set before the
     * 26-week window (an old best not beaten since), its week isn't in the
     * series, so we leave the scaled trend untouched rather than stamp the PR
     * time onto a different, more recent week that never actually ran it.
     *
     * @param  array<string, array{time:int, activity_id:int|null}>  $bestByWeek
     */
    private function snapBestToRecord(array &$bestByWeek, PersonalRecord $record): void
    {
        $recordWeek = Carbon::parse($record->set_at)->startOfWeek(Carbon::MONDAY)->toDateString();
        if (isset($bestByWeek[$recordWeek])) {
            $bestByWeek[$recordWeek] = [
                'time' => (int) round($record->value_sec),
                'activity_id' => $record->activity_id,
            ];
        }
    }

    /**
     * @param  Collection<int, ActivityDetail>  $rows
     * @return array<string, array{time:int, activity_id:int|null}>
     */
    private function bestTimePerWeek(Collection $rows, float $target): array
    {
        /** @var array<string, array{time:int, activity_id:int|null}> $bestByWeek */
        $bestByWeek = [];
        foreach ($rows as $row) {
            if ($row->start_date_local === null) {
                continue;
            }
            $weekKey = Carbon::parse($row->start_date_local)->startOfWeek(Carbon::MONDAY)->toDateString();
            // Scale the elapsed_time to the exact target distance so weeks with
            // a 9.7km run and a 10.2km run compare apples-to-apples.
            $scaled = (int) round((int) $row->elapsed_time * ($target / (float) $row->distance));
            if (! isset($bestByWeek[$weekKey]) || $scaled < $bestByWeek[$weekKey]['time']) {
                $bestByWeek[$weekKey] = ['time' => $scaled, 'activity_id' => $row->activity_id];
            }
        }

        return $bestByWeek;
    }
}
