<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A self-reported running experience level, collected during onboarding.
 * Its only consumer today is {@see \App\Services\Run\Plan\TrainingBaseline},
 * which seeds a new athlete's zero-history cold-start defaults from it —
 * real logged behavior always wins once any exists.
 */
enum ExperienceLevel: string
{
    case NewToRunning = 'new_to_running';
    case Returning = 'returning';
    case Experienced = 'experienced';
}
