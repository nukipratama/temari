<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Services\Gamification\UnlockEngine;
use App\Services\AI\AnalysisType;
use App\Services\AI\AnalysisService;
use App\Services\Run\Metrics\StreamSummary;

use function is_array;

class RunCardFactory
{
    private const int LONG_SLOW_DISTANCE_THRESHOLD_M = 12_000;

    private const int LONG_SLOW_DISTANCE_DURATION_S = 3_600;

    public function __construct(
        private readonly SpecialMoves $specialMoves,
        private readonly AnalysisService $analysisService,
        private readonly UnlockEngine $unlockEngine,
    ) {
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

        $card = RunCard::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            [
                'rarity' => $rarity,
                'badges' => $badges,
                'special_move' => $move,
            ],
        );

        $this->analysisService->request(
            subjectOrType: RunCard::class,
            subjectId: $card->id,
            type: AnalysisType::CardFlavor,
            invalidate: true,
        );

        if (in_array($card->rarity, [RunCard::RARITY_EPIK, RunCard::RARITY_LEGENDARIS], strict: true)) {
            $this->unlockEngine->grantEligible($activity->user);
        }

        return $card;
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
            $isLongest && $distance >= 21_097.5 => RunCard::RARITY_LEGENDARIS,
            $prSet => RunCard::RARITY_EPIK,
            $negativeSplit && $distance >= 5_000 => RunCard::RARITY_LANGKA,
            $hasZoneData && $distance >= 3_000 => RunCard::RARITY_JARANG,
            default => RunCard::RARITY_BIASA,
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
            $badges[] = RunCard::BADGE_HARI_PANAS;
        }
        if ($detail->weather_rain_detected === true) {
            $badges[] = RunCard::BADGE_PEJUANG_HUJAN;
        }
        if ($detail->start_date_local !== null && (int) $detail->start_date_local->format('H') < 6) {
            $badges[] = RunCard::BADGE_ANAK_PAGI;
        }
        if ($this->isLongSlowDistance($detail, $summary)) {
            $badges[] = RunCard::BADGE_LONG_SLOW_DISTANCE;
        }
        if (($summary['negative_split'] ?? false) === true) {
            $badges[] = RunCard::BADGE_NEGATIVE_SPLIT;
        }
        if ($this->isAerobicDiscipline($detail, $summary)) {
            $badges[] = RunCard::BADGE_TAHAN_DIRI;
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
