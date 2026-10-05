<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Enums\PrCategory;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use App\Actions\Run\Metrics\ResolveDistanceRecordsAction;

class PersonalRecords
{
    public function __construct(
        private readonly ResolveDistanceRecordsAction $distanceRecords,
        private readonly VdotEstimator $vdotEstimator,
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
        $this->vdotEstimator->captureProvisionalAnchor($user);
        PersonalRecord::query()->where('user_id', $user->id)->delete();
        $this->distanceRecords->forget($user->id);

        foreach ($this->chronologically($user) as $activity) {
            $detail = $activity->detail;
            if ($detail !== null) {
                $this->storeRecords($activity, $detail);
            }
        }

        $this->vdotEstimator->forget($user);
        $this->vdotEstimator->captureProvisionalAnchor($user);
    }

    /**
     * The runs that set a record on the day they were run, each judged against
     * every earlier run rather than against whatever happened to be ingested
     * before it. Writes nothing.
     *
     * @return list<int>
     */
    public function recordSettingActivityIds(User $user): array
    {
        $best = [];
        $setters = [];

        foreach ($this->chronologically($user) as $activity) {
            $detail = $activity->detail;
            if ($detail === null) {
                continue;
            }

            foreach ($this->categoryValues($detail) as $category => $value) {
                if (isset($best[$category]) && $value >= $best[$category]) {
                    continue;
                }

                $best[$category] = $value;
                $setters[$activity->id] = $activity->id;
            }
        }

        return array_values($setters);
    }

    /**
     * @return LazyCollection<int, Activity>
     */
    private function chronologically(User $user): LazyCollection
    {
        return Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->orderBy('activity_details.start_date_local')
            ->orderBy('activities.id')
            ->with('detail')
            ->select('activities.*')
            ->lazy();
    }

    /**
     * @return list<string>
     */
    public function detectAndStore(Activity $activity, ActivityDetail $detail): array
    {
        return DB::transaction(function () use ($activity, $detail): array {
            $this->vdotEstimator->captureProvisionalAnchor($activity->user);

            $broken = $this->storeRecords($activity, $detail);

            $this->vdotEstimator->forget($activity->user);
            $this->vdotEstimator->captureProvisionalAnchor($activity->user);

            return $broken;
        });
    }

    /** @return list<string> */
    private function storeRecords(Activity $activity, ActivityDetail $detail): array
    {
        $setAt = $detail->start_date_local ?? Carbon::now();
        $broken = [];

        foreach ($this->categoryValues($detail) as $category => $value) {
            if ($this->updateIfFaster($activity, PrCategory::from($category), $value, $setAt)) {
                $broken[] = $category;
            }
        }

        return $broken;
    }

    /**
     * The run's time for every category it qualifies for: distances first, then efforts.
     *
     * @return array<string, float>
     */
    private function categoryValues(ActivityDetail $detail): array
    {
        $summary = StreamSummary::fromArray($detail->streamSummary());
        $values = RunDistanceTimes::forDetail($detail);

        foreach (PrCategory::efforts() as $category) {
            $window = $category->effortWindow();
            if ($window === null) {
                continue;
            }
            $label = $summary->bestPace($window);
            $value = $label === null ? null : PaceFormatter::parse($label);
            if ($value !== null) {
                $values[$category->value] = $value;
            }
        }

        return $values;
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
