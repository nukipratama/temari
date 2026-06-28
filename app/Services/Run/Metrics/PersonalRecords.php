<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Enums\PrCategory;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Gamification\UnlockEngine;
use App\Services\AI\AnalysisType;
use App\Services\AI\AnalysisService;
use Illuminate\Support\Carbon;

class PersonalRecords
{
    public function __construct(
        private readonly AnalysisService $analysisService,
        private readonly UnlockEngine $unlockEngine,
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
            ->get();

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
            $this->unlockEngine->grantEligible($activity->user);
        }

        return $broken;
    }

    /**
     * @return list<string>
     */
    private function checkDistancePrs(Activity $activity, ActivityDetail $detail, Carbon $setAt): array
    {
        $distance = (float) ($detail->distance ?? 0);
        $splits = is_array($detail->splits_metric) ? $detail->splits_metric : [];
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
        $streamSummary = $detail->streamSummary();
        $broken = [];

        foreach (PrCategory::efforts() as $category) {
            $key = $category->effortStreamKey();
            if ($key === null) {
                continue;
            }
            $label = $streamSummary[$key] ?? null;
            if (! is_string($label)) {
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
     * @param  array<int, array<string, mixed>>  $splits
     */
    public function timeAtDistance(array $splits, float $targetMeters): ?float
    {
        $accDist = 0.0;
        $accTime = 0.0;
        foreach ($splits as $split) {
            $distance = (float) ($split['distance'] ?? 0);
            $time = (float) ($split['moving_time'] ?? 0);
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
        $existing = PersonalRecord::query()
            ->where('user_id', $activity->user_id)
            ->where('category', $category->value)
            ->first();

        if ($existing !== null && $value >= $existing->value_sec) {
            return false;
        }

        $pr = PersonalRecord::query()->updateOrCreate(
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

        // invalidate:false so a chronological backfill (each historical run
        // beats the same category record in turn) does not re-bill pr_context on
        // every beat: the idempotency guard skips a row that is already Done. The
        // narrator reads the live PR row at job time, so a still-pending row
        // narrates the LATEST value regardless of how many beats preceded it.
        $this->analysisService->request(
            subjectOrType: PersonalRecord::class,
            subjectId: $pr->id,
            type: AnalysisType::PrContext,
            invalidate: false,
        );

        return true;
    }
}
