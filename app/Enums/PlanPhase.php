<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backed enum for a planned session's periodization phase. `Deload` only
 * appears in the self-scaled (no active race) mesocycle; `Peak`/`Taper` only
 * appear in race-oriented mode, since neither exists without a race date to
 * count back from ({@see \App\Services\Run\Plan\PhaseSchedule}).
 */
enum PlanPhase: string
{
    case Base = 'base';
    case Build = 'build';
    case Peak = 'peak';
    case Taper = 'taper';
    case Deload = 'deload';
}
