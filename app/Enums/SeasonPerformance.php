<?php

declare(strict_types=1);

namespace App\Enums;

enum SeasonPerformance: string
{
    case None = 'none';
    case Unrecorded = 'unrecorded';
    case Pending = 'pending';
    case Met = 'met';
    case NotMet = 'not_met';
    case DidNotRun = 'did_not_run';
    case Cancelled = 'cancelled';
}
