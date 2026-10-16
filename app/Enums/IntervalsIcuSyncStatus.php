<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where today's workout stands on the athlete's intervals.icu calendar, as
 * Today's chip shows it.
 */
enum IntervalsIcuSyncStatus: string
{
    case Sent = 'sent';
    /** The day has no paces and intervals.icu has no run threshold heart rate to target instead. */
    case NoThresholdHeartRate = 'no_threshold_hr';
    case Unreachable = 'unreachable';
}
