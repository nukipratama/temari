<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
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

    private const array DISTANCE_CATEGORIES = [
        '1km' => 1_000,
        '5km' => 5_000,
        '10km' => 10_000,
        '15km' => 15_000,
        'half_marathon' => 21_097.5,
        'marathon' => 42_195.0,
    ];

    /** category → stream_summary key */
    private const array EFFORT_CATEGORIES = [
        'best_5min' => 'best_5min_pace',
        'best_10min' => 'best_10min_pace',
        'best_20min' => 'best_20min_pace',
        'best_30min' => 'best_30min_pace',
        'best_60min' => 'best_60min_pace',
    ];

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

        foreach (self::DISTANCE_CATEGORIES as $category => $targetMeters) {
            if ($distance < $targetMeters * 0.95) {
                continue;
            }
            $value = $this->timeAtDistance($splits, $targetMeters);
            if ($value === null || $value <= 0) {
                continue;
            }
            if ($this->updateIfFaster($activity, $category, $value, $setAt)) {
                $broken[] = $category;
            }
        }

        return $broken;
    }

    /**
     * @return list<string>
     */
    private function checkEffortPrs(Activity $activity, ActivityDetail $detail, Carbon $setAt): array
    {
        $streamSummary = is_array($detail->stream_summary) ? $detail->stream_summary : [];
        $broken = [];

        foreach (self::EFFORT_CATEGORIES as $category => $key) {
            $label = $streamSummary[$key] ?? null;
            if (! is_string($label)) {
                continue;
            }
            $value = PaceFormatter::parse($label);
            if ($value === null) {
                continue;
            }
            if ($this->updateIfFaster($activity, $category, $value, $setAt)) {
                $broken[] = $category;
            }
        }

        return $broken;
    }

    /**
     * Interpolates elapsed_time at exactly $targetMeters using per-km splits.
     *
     * @param  array<int, array<string, mixed>>  $splits
     */
    public function timeAtDistance(array $splits, float $targetMeters): ?float
    {
        $accDist = 0.0;
        $accTime = 0.0;
        foreach ($splits as $split) {
            $distance = (float) ($split['distance'] ?? 0);
            $time = (float) ($split['elapsed_time'] ?? 0);
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

    private function updateIfFaster(Activity $activity, string $category, float $value, Carbon $setAt): bool
    {
        $existing = PersonalRecord::query()
            ->where('user_id', $activity->user_id)
            ->where('category', $category)
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

        $this->analysisService->request(
            subjectOrType: PersonalRecord::class,
            subjectId: $pr->id,
            type: AnalysisType::PrContext,
            invalidate: true,
        );

        return true;
    }
}
