<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Enums\Rarity;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\StreamSummary;

/**
 * Pure rarity scoring: reads whole-history facts off the {@see CardContext}
 * rather than querying for them.
 */
final readonly class RarityScorer
{
    /**
     * Ceiling on how much the badge count alone can lift a card's rarity; badges stack with
     * circumstance, not merit.
     */
    private const int MAX_BADGE_SCORE = 3;

    public function __construct(
        private BadgeEvaluator $badgeEvaluator,
    ) {
    }

    /**
     * Compute the rarity score from point sources.
     *
     * Point sources:
     *  +3 PR set
     *  +2 negative split
     *  +2 long run (>=12km)
     *  +2 executed a planned Tempo/Interval session at or faster than its prescribed pace
     *  +1 first distance bracket
     *  +1 per badge earned
     *  +1 zone discipline (<10% Z3+ on >=10km)
     *  +1 weekly consistency (>=3 runs this week)
     *
     * @param  array<int, string>  $badges
     */
    public function score(
        ActivityDetail $detail,
        StreamSummary $summary,
        array $badges,
        bool $prSet,
        CardContext $context,
    ): int {
        $score = 0;
        $distance = (float) ($detail->distance ?? 0);
        $negativeSplit = $summary->negativeSplit() === true;

        if ($prSet) {
            $score += 3;
        }
        if ($negativeSplit) {
            $score += 2;
        }
        if ($distance >= BadgeEvaluator::LONG_SLOW_DISTANCE_THRESHOLD_M) {
            $score += 2;
        }
        if ($context->qualitySessionPaceMet) {
            $score += 2;
        }
        if ($context->isFirstDistanceBracket) {
            $score += 1;
        }
        $score += min(count($badges), self::MAX_BADGE_SCORE);
        if ($this->badgeEvaluator->isAerobicDiscipline($detail, $summary)) {
            $score += 1;
        }
        if ($context->weeklyConsistency) {
            $score += 1;
        }

        return $score;
    }

    /**
     * Map a point total to a rarity tier.
     *
     * Tiers: 0-4 Common, 5-6 Uncommon, 7-8 Rare, 9 Epic, 10+ Legendary
     */
    public function fromScore(int $score): Rarity
    {
        return match (true) {
            $score >= 10 => Rarity::Legendary,
            $score >= 9 => Rarity::Epic,
            $score >= 7 => Rarity::Rare,
            $score >= 5 => Rarity::Uncommon,
            default => Rarity::Common,
        };
    }
}
