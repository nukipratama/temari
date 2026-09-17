<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Why a run's narration may or may not reach the LLM, as resolved by
 * {@see NarrationEligibility}. Each call site matches every case onto its own
 * outcome, so a new reason cannot be silently ignored by one of them.
 */
enum NarrationVerdict
{
    case Eligible;
    case Demo;
    case TooOld;
    case PreConnect;
    case AwaitingBacklog;
    case Inactive;
}
