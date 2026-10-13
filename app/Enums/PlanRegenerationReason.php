<?php

declare(strict_types=1);

namespace App\Enums;

enum PlanRegenerationReason: string
{
    case Manual = 'manual';
    case Settings = 'settings';
    case Onboarding = 'onboarding';
}
