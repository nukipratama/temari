<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Actions\Gamification\GrantEligibleUnlocksAction;
use App\Enums\PrCategory;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PersonalRecords
{
    public function __construct(
        private readonly GrantEligibleUnlocksAction $unlockEngine,
    ) {
    }

    /**
     * Rebuild the user's personal records from scratch across their remaining
     * activities, oldest-first. Used after an activity is deleted: detectAndStore
     * only ever *lowers* a record, so a deleted run leaves its PR row orphaned
     * (activity_id nulled) with a now-unbeatable time. Dropping every PR and
     * re-detecting chronologically restores the true best of the surviving runs.
     */
    public function rebuildForUser(User $user): void
    {
        PersonalRecord::query()->where('user_id', $user->id)->delete();

        $activities = Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->orderBy('activity_details.start_date_local')
            ->with('detail')
            ->select('activities.*')
            ->lazy();

        foreach ($activities as $activity) {
            $detail = $activity->detail;
            if ($detail !== null) {
                $this->detectAndStore($activity, $detail);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function detectAndStore(Activity $activity, ActivityDetail $detail): array
    {
        $setAt = $detail->start_date_local ?? Carbon::now();
        $broken = [
            ...$this->checkDistancePrs($activity, $detail, $setAt),
            ...$this->checkEffortPrs($activity, $detail, $setAt),
        ];

        if ($broken !== []) {
            ($this->unlockEngine)($activity->user);
        }

        return $broken;
    }

    /**
     * @return list<string>
     */
    private function checkDistancePrs(Activity $activity, ActivityDetail $detail, Carbon $setAt): array
    {
        $distance = (float) ($detail->distance ?? 0);
        $splits = $this->splitRows(StreamSummary::fromArray($detail->streamSummary()));
        $broken = [];

        foreach (PrCategory::distances() as $category) {
            $targetMeters = $category->distanceMeters();
            if ($targetMeters === null || $distance < $targetMeters * 0.99) {
                continue;
            }
            $value = $this->timeAtDistance($splits, $targetMeters);
            if ($value === null || $value <= 0) {
                continue;
            }
            if ($this->updateIfFaster($activity, $category, $value, $setAt)) {
                $broken[] = $category->value;
            }
        }

        return $broken;
    }

    /**
     * @return list<string>
     */
    private function checkEffortPrs(Activity $activity, ActivityDetail $detail, Carbon $setAt): array
    {
        $streamSummary = StreamSummary::fromArray($detail->streamSummary());
        $broken = [];

        foreach (PrCategory::efforts() as $category) {
            $window = $category->effortWindow();
            if ($window === null) {
                continue;
            }
            $label = $streamSummary->bestPace($window);
            if ($label === null) {
                continue;
            }
            $value = PaceFormatter::parse($label);
            if ($value === null) {
                continue;
            }
            if ($this->updateIfFaster($activity, $category, $value, $setAt)) {
                $broken[] = $category->value;
            }
        }

        return $broken;
    }

    /**
     * The run's segments in order: every full kilometre, then the trailing
     * sub-km leftover. The window needs that leftover to reach a target that
     * lands inside it — a 42.6 km run only covers 42 full kilometres, so the
     * marathon PR sits in the final 600 m. The leftover's time is recovered
     * from its already-normalized pace, the one place Strava's `moving_time`
     * still shows through until KmSplitBuilder derives the partial itself.
     *
     * @return list<array<string, mixed>>
     */
    private function splitRows(StreamSummary $summary): array
    {
        $rows = array_values($summary->perKm() ?? []);

        $partial = $summary->partialSplit() ?? [];
        $pace = $partial['pace'] ?? null;
        $paceSec = is_string($pace) ? PaceFormatter::parse($pace) : null;
        $distance = (float) ($partial['distance_m'] ?? 0);
        if ($paceSec !== null && $distance > 0) {
            $rows[] = ['distance_m' => $distance, 'elapsed_sec' => $paceSec * $distance / 1000];
        }

        return $rows;
    }

    /**
     * Fastest time over any contiguous window of splits that covers the target
     * distance, so a negative-split run records its genuine best embedded effort
     * rather than only its opening segment. Null when no window reaches the target.
     *
     * @param  array<int, array<string, mixed>>  $splits
     */
    public function timeAtDistance(array $splits, float $targetMeters): ?float
    {
        $best = null;
        $count = count($splits);

        for ($start = 0; $start < $count; $start++) {
            $window = $this->windowTime(array_slice($splits, $start), $targetMeters);
            if ($window !== null && ($best === null || $window < $best)) {
                $best = $window;
            }
        }

        return $best;
    }

    /**
     * Time to cover the target distance from the first split onward, interpolating
     * within the final partial split. Null when the given splits fall short.
     *
     * @param  array<int, array<string, mixed>>  $splits
     */
    private function windowTime(array $splits, float $targetMeters): ?float
    {
        $accDist = 0.0;
        $accTime = 0.0;
        foreach ($splits as $split) {
            $distance = (float) ($split['distance_m'] ?? 0);
            $time = (float) ($split['elapsed_sec'] ?? 0);
            if ($distance <= 0 || $time <= 0) {
                continue;
            }
            if ($accDist + $distance >= $targetMeters) {
                $remaining = $targetMeters - $accDist;

                return $accTime + $time * ($remaining / $distance);
            }
            $accDist += $distance;
            $accTime += $time;
        }

        return null;
    }

    private function updateIfFaster(Activity $activity, PrCategory $category, float $value, Carbon $setAt): bool
    {
        // Locked read + write in one transaction: two activities for the same
        // user can be ingested concurrently on different workers, and a plain
        // check-then-act here let both pass the "no existing PR" check and
        // race each other into the user_id+category unique constraint.
        return DB::transaction(function () use ($activity, $category, $value, $setAt): bool {
            $existing = PersonalRecord::query()
                ->where('user_id', $activity->user_id)
                ->where('category', $category->value)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $value >= $existing->value_sec) {
                return false;
            }

            PersonalRecord::query()->updateOrCreate(
                [
                    'user_id' => $activity->user_id,
                    'category' => $category,
                ],
                [
                    'value_sec' => $value,
                    'activity_id' => $activity->id,
                    'set_at' => $setAt,
                ],
            );

            return true;
        });
    }
}
