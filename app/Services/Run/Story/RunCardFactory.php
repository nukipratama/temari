<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Services\Run\Metrics\StreamSummary;

use function is_array;

/**
 * Builds + persists a `run_cards` row for an activity. Each card has:
 *
 *   rarity        biasa | jarang | langka | epik | legendaris
 *   badges        flavor descriptors earned by run context (hot day,
 *                 rain, dawn start, LSD, negative split, aerobic discipline)
 *   special_move  one nominated by `SpecialMoves` based on stream summary
 *
 * Idempotent: re-running for the same activity overwrites the existing
 * card (so re-ingest after a calc bugfix produces a fresh card).
 */
class RunCardFactory
{
    private const int LONG_SLOW_DISTANCE_THRESHOLD_M = 12_000;

    /** Total elapsed (seconds) above which a run counts as "long". */
    private const int LONG_SLOW_DISTANCE_DURATION_S = 3_600;

    public function __construct(private readonly SpecialMoves $specialMoves)
    {
    }

    public function build(Activity $activity, ActivityDetail $detail): RunCard
    {
        $summary = is_array($detail->stream_summary) ? $detail->stream_summary : [];
        $prSet = $this->hasPrFromThisActivity($activity);
        $isLongest = $this->isAllTimeLongest($activity, $detail);

        $rarity = $this->rarity($detail, $summary, $prSet, $isLongest);
        $badges = $this->badges($detail, $summary);
        $move = $this->specialMoves->pick($summary, [
            'distance_m' => $detail->distance,
            'pr_set' => $prSet,
        ]);

        return RunCard::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            [
                'rarity' => $rarity,
                'badges' => $badges,
                'special_move' => $move,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function rarity(ActivityDetail $detail, array $summary, bool $prSet, bool $isLongest): string
    {
        $distance = (float) ($detail->distance ?? 0);
        $negativeSplit = ($summary['negative_split'] ?? false) === true;
        $hasZoneData = is_array($summary['time_in_zone_pct'] ?? null);

        return match (true) {
            $isLongest && $distance >= 21_097.5 => 'legendaris',
            $prSet => 'epik',
            $negativeSplit && $distance >= 5_000 => 'langka',
            $hasZoneData && $distance >= 3_000 => 'jarang',
            default => 'biasa',
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    private function badges(ActivityDetail $detail, array $summary): array
    {
        $badges = [];

        if (($detail->weather_temp_c ?? 0) >= 31) {
            $badges[] = 'hari_panas';
        }
        if ($detail->weather_rain_detected === true) {
            $badges[] = 'pejuang_hujan';
        }
        if ($detail->start_date_local !== null && (int) $detail->start_date_local->format('H') < 6) {
            $badges[] = 'anak_pagi';
        }
        if ($this->isLongSlowDistance($detail, $summary)) {
            $badges[] = 'long_slow_distance';
        }
        if (($summary['negative_split'] ?? false) === true) {
            $badges[] = 'negative_split';
        }
        if ($this->isAerobicDiscipline($detail, $summary)) {
            $badges[] = 'tahan_diri';
        }

        return $badges;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function isLongSlowDistance(ActivityDetail $detail, array $summary): bool
    {
        $distance = $detail->distance ?? 0;
        $elapsed = $detail->elapsed_time ?? 0;
        if ($distance < self::LONG_SLOW_DISTANCE_THRESHOLD_M || $elapsed < self::LONG_SLOW_DISTANCE_DURATION_S) {
            return false;
        }

        return StreamSummary::hardZoneShare($summary) < 25.0;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function isAerobicDiscipline(ActivityDetail $detail, array $summary): bool
    {
        $distance = $detail->distance ?? 0;
        if ($distance < 10_000) {
            return false;
        }

        return StreamSummary::hardZoneShare($summary) < 10.0;
    }

    private function hasPrFromThisActivity(Activity $activity): bool
    {
        return PersonalRecord::query()
            ->where('activity_id', $activity->id)
            ->exists();
    }

    private function isAllTimeLongest(Activity $activity, ActivityDetail $detail): bool
    {
        $distance = $detail->distance ?? 0;
        if ($distance <= 0) {
            return false;
        }

        $existingMax = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $activity->user_id)
            ->where('activities.id', '!=', $activity->id)
            ->max('activity_details.distance');

        return $existingMax === null || $distance > (float) $existingMax;
    }
}
