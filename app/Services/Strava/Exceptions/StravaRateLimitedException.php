<?php

declare(strict_types=1);

namespace App\Services\Strava\Exceptions;

use RuntimeException;

class StravaRateLimitedException extends RuntimeException
{
    /**
     * Seconds the caller should wait before retrying, seeded from Strava's
     * Retry-After header when present. Null when the limit was tripped by a
     * local bucket guard with no upstream hint.
     */
    public function __construct(string $message = '', public readonly ?int $availableIn = null)
    {
        parent::__construct($message);
    }
}
