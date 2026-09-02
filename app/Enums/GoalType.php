<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the athlete says they're chasing right now, collected during
 * onboarding. Narration-flavor only — {@see \App\Models\RaceGoal}'s presence
 * or absence already fully drives periodization mode (race-oriented vs.
 * self-scaled); this field carries no computational weight of its own.
 */
enum GoalType: string
{
    case Consistent = 'consistent';
    case Race = 'race';
    case Base = 'base';
    case Return = 'return';
}
