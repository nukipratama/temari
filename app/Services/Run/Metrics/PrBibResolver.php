<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RecordStamp;

/**
 * The run hero's one-time "PR bib" stamp: whether the viewed run currently
 * holds one of the tracked records, and whether that record's stamp has
 * already played for this account.
 *
 * Tracked records are the 5K/10K/half/full bests plus the account's longest
 * run — not the best-effort pace windows or the 1 km/15 km distances
 * {@see PersonalRecord} also tracks. Marking a record "seen" happens here, on
 * render: the first request that finds an unseen, currently-held record wins
 * the {@see RecordStamp} row (unique on user+record), so a refresh or a
 * second device never replays it.
 */
class PrBibResolver
{
    /** Priority order: the rarer record wins the stamp when a run holds more than one. */
    private const array TRACKED_CATEGORIES = [
        'marathon' => 'FM',
        'half_marathon' => 'HM',
        '10km' => '10K',
        '5km' => '5K',
    ];

    private const string LONGEST_RUN_KEY = 'longest_run';

    /**
     * @return array{label: string, value_sec: float|null, distance_m: float|null}|null
     */
    public function resolve(Activity $activity, ActivityDetail $detail): ?array
    {
        foreach ($this->candidates($activity, $detail) as $candidate) {
            $stamp = RecordStamp::query()->firstOrCreate(
                ['user_id' => $activity->user_id, 'record_key' => $candidate['key']],
                ['seen_at' => now()],
            );

            if ($stamp->wasRecentlyCreated) {
                return [
                    'label' => $candidate['label'],
                    'value_sec' => $candidate['value_sec'] ?? null,
                    'distance_m' => $candidate['distance_m'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * @return list<array{key: string, label: string, value_sec?: float, distance_m?: float}>
     */
    private function candidates(Activity $activity, ActivityDetail $detail): array
    {
        $candidates = [];

        foreach (self::TRACKED_CATEGORIES as $category => $label) {
            $pr = PersonalRecord::query()
                ->where('user_id', $activity->user_id)
                ->where('category', $category)
                ->first();

            if ($pr !== null && $pr->activity_id === $activity->id) {
                $candidates[] = ['key' => $category, 'label' => $label, 'value_sec' => $pr->value_sec];
            }
        }

        if ($this->isLongestRun($activity, $detail)) {
            $candidates[] = [
                'key' => self::LONGEST_RUN_KEY,
                'label' => 'Longest Run',
                'distance_m' => (float) ($detail->distance ?? 0),
            ];
        }

        return $candidates;
    }

    /** Whether this run's distance beats every other run this account has ever logged. */
    private function isLongestRun(Activity $activity, ActivityDetail $detail): bool
    {
        $distance = (float) ($detail->distance ?? 0);
        if ($distance <= 0) {
            return false;
        }

        $maxOther = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $activity->user_id)
            ->where('activities.id', '!=', $activity->id)
            ->max('activity_details.distance');

        return $distance > (float) ($maxOther ?? 0);
    }
}
