<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

/**
 * The whole-history facts a card needs, resolved once per build by
 * {@see CardContextBuilder} so {@see BadgeEvaluator} and {@see RarityScorer}
 * stay pure functions of the run in front of them.
 */
final readonly class CardContext
{
    public function __construct(
        public bool $isFirstRunEver,
        public bool $isFirstDistanceBracket,
        public bool $weeklyConsistency,
        public int $consecutiveDaysBefore,
        /** Null when this run has no average HR, so no effort ratio is computable. */
        public ?int $athleteMaxHr,
    ) {
    }
}
