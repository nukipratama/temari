<?php

declare(strict_types=1);

namespace App\Services\IntervalsIcu\Exceptions;

use RuntimeException;

/**
 * intervals.icu answered 401/403: the athlete's key no longer works, so
 * retrying will not help until they reconnect.
 */
class IntervalsIcuRejectedException extends RuntimeException
{
}
