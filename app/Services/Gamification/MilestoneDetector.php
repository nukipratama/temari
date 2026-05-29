<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\PrCategory;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\PaceCalculator;

/**
 * Picks out "first-ever" / PR / longest-ever moments from a freshly-ingested
 * activity so the Dashboard can celebrate them with a banner.
 *
 * Idempotent: an activity that already has `milestones_detected_at` set is
 * skipped, so re-syncs don't re-fire the celebration. The detector caches
 * its result on `activity.milestone_payload` (JSON list) which the dismiss
 * endpoint nulls out when the user taps "tutup".
 *
 * Chronological correctness: "first ever" is judged by `start_date_local`,
 * not Activity.id — users backfilling a year of Strava history sync in
 * arbitrary order, and ID-based first-ever would mis-fire on every row.
 */
class MilestoneDetector
{
    /** Pace thresholds in seconds-per-km, sorted descending (slowest first). */
    private const array PACE_THRESHOLDS_SEC = [
        7 * 60,
        6 * 60,
        5 * 60,
        4 * 60 + 30,
    ];

    /**
     * Process a single just-ingested activity. Returns the (possibly empty)
     * milestone list and persists it to the activity row.
     *
     * @param  list<string>  $newPrCategories  Categories returned by PersonalRecords::detectAndStore for this activity.
     * @return list<array{kind: string, label: string, body: string, priority: int}>
     */
    public function detect(Activity $activity, ActivityDetail $detail, array $newPrCategories = []): array
    {
        if ($activity->milestones_detected_at !== null) {
            return $this->cachedPayload($activity);
        }

        $milestones = $this->compute($activity, $detail, $newPrCategories);

        $activity->update([
            'milestones_detected_at' => now(),
            'milestone_payload' => $milestones === [] ? null : $milestones,
        ]);

        return $milestones;
    }

    /**
     * @return list<array{kind: string, label: string, body: string, priority: int}>
     */
    private function cachedPayload(Activity $activity): array
    {
        $payload = $activity->milestone_payload;
        if (! is_array($payload)) {
            return [];
        }

        /**
         * Eloquent casts the JSON back to a generic array<string, mixed>; the
         * shape is enforced by the detector itself when writing.
         *
         * @var list<array{kind: string, label: string, body: string, priority: int}>
         */
        return array_values($payload);
    }

    /**
     * @param  list<string>  $newPrCategories
     * @return list<array{kind: string, label: string, body: string, priority: int}>
     */
    private function compute(Activity $activity, ActivityDetail $detail, array $newPrCategories): array
    {
        if ($detail->start_date_local === null) {
            return [];
        }

        $milestones = [];

        foreach ($newPrCategories as $category) {
            $label = PrCategory::tryFrom($category)?->label() ?? str_replace('_', ' ', $category);
            $milestones[] = [
                'kind' => 'pr',
                'label' => 'Personal Record!',
                'body' => sprintf('Kamu baru saja memecahkan PR di %s. Aku catat.', $label),
                'priority' => 100,
            ];
        }

        $longestEver = $this->longestEverBefore($activity, $detail);
        $distanceMeters = (float) ($detail->distance ?? 0);
        if ($longestEver !== null && $distanceMeters > $longestEver) {
            $milestones[] = [
                'kind' => 'longest_ever',
                'label' => 'Lari terjauh sampai sekarang',
                'body' => sprintf('%.2f km, melampaui rekor jarak kamu sebelumnya.', $distanceMeters / 1000),
                'priority' => 90,
            ];
        }

        $distanceKm = $distanceMeters / 1000;
        $distanceMilestone = $this->firstEverDistance($activity, $detail, $distanceKm);
        if ($distanceMilestone !== null) {
            $milestones[] = $distanceMilestone;
        }

        $paceFloat = PaceCalculator::secPerKm((float) $distanceMeters, $detail->moving_time);
        if ($paceFloat !== null) {
            // Whole-second pace: a 7:00.4/km run is compared as 7:01.
            $paceSec = (int) round($paceFloat);
            $paceMilestone = $this->firstEverPace($activity, $detail, $paceSec);
            if ($paceMilestone !== null) {
                $milestones[] = $paceMilestone;
            }
        }

        usort($milestones, fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return $milestones;
    }

    private function longestEverBefore(Activity $activity, ActivityDetail $detail): ?float
    {
        return ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $activity->user_id)->where('id', '!=', $activity->id))
            ->where('start_date_local', '<', $detail->start_date_local)
            ->max('distance');
    }

    /**
     * @return array{kind: string, label: string, body: string, priority: int}|null
     */
    private function firstEverDistance(Activity $activity, ActivityDetail $detail, float $distanceKm): ?array
    {
        $thresholdReached = null;
        foreach (PrCategory::distances() as $category) {
            $thresholdKm = ($category->distanceMeters() ?? 0) / 1000;
            if ($distanceKm >= $thresholdKm) {
                $thresholdReached = $thresholdKm;
            }
        }
        if ($thresholdReached === null) {
            return null;
        }

        $existed = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $activity->user_id)->where('id', '!=', $activity->id))
            ->where('start_date_local', '<', $detail->start_date_local)
            ->where('distance', '>=', $thresholdReached * 1000)
            ->exists();

        if ($existed) {
            return null;
        }

        return [
            'kind' => 'first_ever_distance',
            'label' => sprintf('%s pertama kamu!', $this->formatKmLabel($thresholdReached)),
            'body' => sprintf('Pertama kali kamu lari sejauh %.2f km. Tandai momen ini.', $distanceKm),
            'priority' => 50 + (int) round($thresholdReached * 2),
        ];
    }

    /**
     * @return array{kind: string, label: string, body: string, priority: int}|null
     */
    private function firstEverPace(Activity $activity, ActivityDetail $detail, int $paceSec): ?array
    {
        $thresholdMatched = null;
        foreach (self::PACE_THRESHOLDS_SEC as $threshold) {
            if ($paceSec <= $threshold) {
                $thresholdMatched = $threshold;
                break;
            }
        }
        if ($thresholdMatched === null) {
            return null;
        }

        $existed = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $activity->user_id)->where('id', '!=', $activity->id))
            ->where('start_date_local', '<', $detail->start_date_local)
            ->whereNotNull('distance')
            ->where('distance', '>', 0)
            ->whereNotNull('moving_time')
            ->whereRaw('moving_time / (distance / 1000) <= ?', [$thresholdMatched])
            ->exists();

        if ($existed) {
            return null;
        }

        $label = $this->formatPaceLabel($thresholdMatched);

        return [
            'kind' => 'first_ever_pace',
            'label' => sprintf('Pace sub-%s pertama!', $label),
            'body' => sprintf('Pace kamu turun di bawah %s/km. Lari berkualitas!', $label),
            'priority' => 30 + (int) round((420 - $thresholdMatched) / 6),
        ];
    }

    private function formatKmLabel(float $km): string
    {
        if (abs($km - 21.1) < 0.05) {
            return 'Half Marathon';
        }
        if (abs($km - 42.2) < 0.05) {
            return 'Marathon';
        }

        return rtrim(rtrim(number_format($km, 1, '.', ''), '0'), '.').' km';
    }

    private function formatPaceLabel(int $paceSec): string
    {
        $m = intdiv($paceSec, 60);
        $s = $paceSec % 60;

        return $s === 0 ? sprintf('%d:00', $m) : sprintf('%d:%02d', $m, $s);
    }
}
