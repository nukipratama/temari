<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Enums\Rarity;
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
        $summary = $detail->streamSummary();
        $prSet = $this->hasPrFromThisActivity($activity);
        $isLongest = $this->isAllTimeLongest($activity, $detail);

        $rarity = $this->rarity($detail, $summary, $prSet, $isLongest);
        $badges = $this->badges($detail, $summary);
        $move = $this->specialMoves->pick($summary, [
            'distance_m' => $detail->distance,
            'pr_set' => $prSet,
            'seed' => $activity->id, // stable per activity so the name never reshuffles
        ]);

        $existing = RunCard::query()->where('activity_id', $activity->id)->first();
        $previousRarityRank = $existing?->rarity->rank() ?? -1;

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

        if (in_array($card->rarity, [Rarity::Epic, Rarity::Legendary], strict: true)) {
            $this->unlockEngine->grantEligible($activity->user);
        }

        // Queue this card for the reveal modal when it's freshly created or
        // its rarity climbed since the last build. Re-running build with the
        // same rarity does NOT re-trigger the reveal.
        if ($card->rarity->rank() > $previousRarityRank) {
            $this->queueRevealFor($activity, $card);
        }

        return $card;
    }

    /**
     * Stash the card id on the user so the next page load can pop the reveal
     * modal. Only one reveal can be pending at a time: if an unseen reveal is
     * already queued we leave it (the earlier one wins), and this newer card is
     * not shown via the modal. It still lands in the collection normally.
     */
    private function queueRevealFor(Activity $activity, RunCard $card): void
    {
        $user = $activity->user;
        if ($user->pending_reveal_card_id !== null) {
            return;
        }
        $user->forceFill(['pending_reveal_card_id' => $card->id])->save();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function rarity(ActivityDetail $detail, array $summary, bool $prSet, bool $isLongest): Rarity
    {
        $distance = (float) ($detail->distance ?? 0);
        $negativeSplit = ($summary['negative_split'] ?? false) === true;
        $hasZoneData = is_array($summary['time_in_zone_pct'] ?? null);

        // Tuned toward a pyramid: most runs land Common, each tier above needs a
        // genuinely rarer condition so Legendaris/Luar Biasa stay special. Uncommon
        // requires a quality signal (a negative split) or a long run — a plodding
        // mid-distance easy run stays Biasa.
        return match (true) {
            $isLongest && $distance >= 21_097.5 => Rarity::Legendary,
            $prSet && $distance >= 10_000 => Rarity::Epic,
            $prSet || ($negativeSplit && $distance >= 8_000) => Rarity::Rare,
            $distance >= 12_000 || ($hasZoneData && $negativeSplit) => Rarity::Uncommon,
            default => Rarity::Common,
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
