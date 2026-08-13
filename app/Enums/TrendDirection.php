<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which way a single Past You comparison points. `Flat` is a real reading, not
 * a missing one: the pair was comparable and the difference sat inside the
 * run-to-run noise band.
 */
enum TrendDirection: string
{
    case Better = 'better';
    case Flat = 'flat';
    case Worse = 'worse';

    public function isBetter(): bool
    {
        return $this === self::Better;
    }

    public function isFlat(): bool
    {
        return $this === self::Flat;
    }

    public function isWorse(): bool
    {
        return $this === self::Worse;
    }
}
