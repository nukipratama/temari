<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

/**
 * The whole-history facts a card needs, resolved once per build by
 * {@see \App\Actions\Run\Story\BuildCardContextAction} so {@see BadgeEvaluator}
 * and {@see RarityScorer} stay pure functions of the run in front of them.
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
        /**
         * True when a planned Tempo/Interval session existed for this run's
         * date and the run's actual pace met or beat that session's
         * prescribed pace band — the rarity dimension that lets a
         * well-executed quality run reach the top tiers without needing
         * distance or a PR. Always false when Slice 6 planning has nothing
         * for this date, or the day wasn't a quality session.
         */
        public bool $qualitySessionPaceMet = false,
    ) {
    }
}
