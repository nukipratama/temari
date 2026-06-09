<?php

declare(strict_types=1);

namespace App\Services\Strava\Exceptions;

use RuntimeException;

/**
 * The Strava API rejected the access token with a 401 — the athlete revoked us
 * (or the token was rotated out). A refresh won't recover it; callers should
 * mark the connection revoked instead of retrying.
 */
class StravaConnectionRevokedException extends RuntimeException
{
}
