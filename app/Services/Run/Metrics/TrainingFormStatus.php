<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * The four states {@see TrainingLoad::formStatus()} classifies form (TSB) into.
 */
enum TrainingFormStatus: string
{
    case Fresh = 'fresh';
    case Optimal = 'optimal';
    case Fatigued = 'fatigued';
    case Overreaching = 'overreaching';
}
