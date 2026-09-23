<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The reading that decided a Past You pair's direction: efficiency (speed per
 * heartbeat) when both runs carry a usable average heart rate, pace otherwise.
 */
enum ComparisonMetric: string
{
    case Ef = 'ef';
    case Pace = 'pace';

    /** Relative change, in percent, at which a pair stops reading as noise. */
    public function signalPct(): float
    {
        return match ($this) {
            self::Ef => 3.0,
            self::Pace => 2.0,
        };
    }
}
