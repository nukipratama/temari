<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backed enum for a planned session's human-readable kind. Distinct from
 * {@see PaceBand}, the VDOT-derived numeric target — a `Long` session
 * typically pairs with the `Easy` pace band unless it's a race-simulation
 * long run at `Marathon` band (see the periodizer's peak-phase handling).
 *
 * `Race` is the goal race itself, the one case whose distance comes from the
 * athlete's {@see \App\Models\RaceGoal} rather than from their training
 * baseline, and the one the readiness clamp never downgrades.
 */
enum SessionType: string
{
    case Easy = 'easy';
    case Long = 'long';
    case Tempo = 'tempo';
    case Interval = 'interval';
    case Rest = 'rest';
    case Race = 'race';
}
