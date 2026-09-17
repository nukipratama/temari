<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a graded day's run did the job its session was written for, as
 * decided by {@see \App\Services\Run\Plan\SessionIntentJudge}. `Unknown` means
 * the recorded data cannot tell, and the day grades on distance alone.
 */
enum IntentVerdict: string
{
    case Hit = 'hit';
    case Missed = 'missed';
    case TooHard = 'too_hard';
    case Unknown = 'unknown';
}
