<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RecordStamp;

/**
 * The run hero's "PR bib" stamp: whether the viewed run currently holds one
 * of the tracked records, and whether this render still owes it the punch-in
 * animation.
 *
 * Tracked records are the 5K/10K/half/full bests plus the account's longest
 * run — not the best-effort pace windows or the 1 km/15 km distances
 * {@see PersonalRecord} also tracks. The badge itself is permanent: a run
 * that holds a record shows it on every view. Only the *animation* is
 * one-time, and this class only reads that fact: `animate` is true whenever
 * no {@see RecordStamp} row exists yet for (user, record) — a GET must not
 * change its own payload, so claiming the stamp happens on the frontend's
 * follow-up POST after the animation plays, not here.
 */
class PrBibResolver
{
    /** Priority order: the rarer record wins the badge when a run holds more than one. */
    public const array TRACKED_CATEGORIES = [
        'marathon' => 'FM',
        'half_marathon' => 'HM',
        '10km' => '10K',
        '5km' => '5K',
    ];

    public const string LONGEST_RUN_KEY = 'longest_run';

    /**
     * @return array{label: string, value_sec: float|null, distance_m: float|null, record_key: string, animate: bool}|null
     */
    public function resolve(Activity $activity, ActivityDetail $detail): ?array
    {
        $candidates = $this->candidates($activity, $detail);
        if ($candidates === []) {
            return null;
        }

        $candidate = $candidates[0];
        $seen = RecordStamp::query()
            ->where('user_id', $activity->user_id)
            ->where('record_key', $candidate['key'])
            ->exists();

        return [
            'label' => $candidate['label'],
            'value_sec' => $candidate['value_sec'] ?? null,
            'distance_m' => $candidate['distance_m'] ?? null,
            'record_key' => $candidate['key'],
            'animate' => ! $seen,
        ];
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
