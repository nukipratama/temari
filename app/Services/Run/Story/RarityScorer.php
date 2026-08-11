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
     * Ceiling on how much the badge count alone can lift a card's rarity.
     *
     * Badges stack with circumstance, not merit: a hot, rainy, early Saturday
     * long run collects several without the run being remarkable. Uncapped they
     * dominated the score and made Langka the most common tier of all.
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
     * Added 2026-08-10 (Slice 7): the quality-execution point. Before it, the
     * non-distance/non-PR ceiling was 8 — a well-executed easy run could
     * already reach Rare, but never Epic/Legendary without distance or a PR.
     * This gives a well-executed *quality* run (Slice 6's `PlannedSession`)
     * its own path to the top tiers, raising that ceiling to 10.
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
     *
     * Refitted 2026-08-10 against `run:compare-recalibration`'s output on the
     * 127-run demo corpus (this was last fitted against 155 runs at 18.1%
     * Rare; the old bands had since drifted to Uncommon modal at 31.5% over
     * Common's 24.4% on this corpus). The new cutoffs are read straight off
     * that run's recomputed score percentiles (p50=4, p75=6, p90=8, p95=9),
     * landing at Common 50%, Uncommon 25%, Rare 15%, Epic 5%, Legendary 5% —
     * Common is modal again, and Rare sits in the same order of magnitude as
     * the original 18.1% fit.
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
