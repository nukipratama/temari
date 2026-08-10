<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backed enum mirroring {@see \App\Services\Run\Metrics\TrainingPaceCalculator::fromVdot()}'s
 * 4 VDOT-derived pace zones (sec/km). Nullable on {@see \App\Models\PlannedSession}:
 * null exactly when `session_type = rest`.
 */
enum PaceBand: string
{
    case Easy = 'easy';
    case Marathon = 'marathon';
    case Threshold = 'threshold';
    case Interval = 'interval';
}
