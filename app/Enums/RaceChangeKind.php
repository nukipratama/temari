<?php

declare(strict_types=1);

namespace App\Enums;

enum RaceChangeKind: string
{
    case Created = 'created';
    case Revised = 'revised';
    case Postponed = 'postponed';
    case Replaced = 'replaced';
    case Cancelled = 'cancelled';
    case Outcome = 'outcome';
}
