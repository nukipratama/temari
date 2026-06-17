<?php

declare(strict_types=1);

namespace App\Services\Strava\Exceptions;

use RuntimeException;

/**
 * The Strava token endpoint failed transiently (401 / 429 / 5xx / connection
 * error) rather than with a permanent 400 invalid_grant. The refresh may well
 * succeed on a retry, so callers should release the job and back off instead of
 * revoking an otherwise-healthy connection.
 */
class StravaTokenRefreshTransientException extends RuntimeException
{
}
