<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The answer to "am I getting better?", computed against the runner's own
 * comparable history and nobody else's.
 *
 * `NotEnoughHistory` is the Past You early-read state, not an error: the
 * rolling window held fewer than three comparable pairs, so there is no trend
 * to call yet.
 */
enum TrendVerdict: string
{
    case Improving = 'improving';
    case Plateaued = 'plateaued';
    case Slipped = 'slipped';
    case Mixed = 'mixed';
    case NotEnoughHistory = 'not_enough_history';
}
