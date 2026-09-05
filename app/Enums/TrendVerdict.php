<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The answer to "am I getting better?", computed against the runner's own
 * comparable history and nobody else's.
 *
 * `NotEnoughHistory` is the Past You empty state (`no-past-match` in the brand
 * set), not an error: the rolling window held fewer than two comparable pairs,
 * so there is nothing to call either way.
 */
enum TrendVerdict: string
{
    case Improving = 'improving';
    case Plateaued = 'plateaued';
    case Slipped = 'slipped';
    case NotEnoughHistory = 'not_enough_history';

    public function isImproving(): bool
    {
        return $this === self::Improving;
    }

    public function isPlateaued(): bool
    {
        return $this === self::Plateaued;
    }

    public function isSlipped(): bool
    {
        return $this === self::Slipped;
    }

    public function isNotEnoughHistory(): bool
    {
        return $this === self::NotEnoughHistory;
    }

    public function isJudged(): bool
    {
        return $this !== self::NotEnoughHistory;
    }
}
