<?php

declare(strict_types=1);

namespace App\Services\Strava\Exceptions;

use RuntimeException;

/**
 * The global Strava circuit breaker is open after a streak of transient failures
 * (5xx / timeouts). Callers should back off and retry once the cooldown elapses,
 * rather than hammering an API that is currently down.
 */
class StravaCircuitOpenException extends RuntimeException
{
}
