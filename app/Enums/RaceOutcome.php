<?php

declare(strict_types=1);

namespace App\Enums;

enum RaceOutcome: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case DidNotRun = 'did_not_run';
    case Cancelled = 'cancelled';
}
