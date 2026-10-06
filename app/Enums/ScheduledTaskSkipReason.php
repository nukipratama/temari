<?php

declare(strict_types=1);

namespace App\Enums;

enum ScheduledTaskSkipReason: string
{
    case Gate = 'gate';
    case Paused = 'paused';
    case Overlapping = 'overlapping';
}
