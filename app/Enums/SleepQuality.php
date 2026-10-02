<?php

declare(strict_types=1);

namespace App\Enums;

enum SleepQuality: string
{
    case Good = 'good';
    case Fair = 'fair';
    case Poor = 'poor';
}
