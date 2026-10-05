<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What became of a time trial run on its day: counted as evidence, waiting on
 * the athlete's answer, or answered as not all-out.
 */
enum TimeTrialOutcome: string
{
    case Confirmed = 'confirmed';
    case Asked = 'asked';
    case Declined = 'declined';
}
