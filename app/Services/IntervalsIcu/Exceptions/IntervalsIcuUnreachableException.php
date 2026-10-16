<?php

declare(strict_types=1);

namespace App\Services\IntervalsIcu\Exceptions;

use RuntimeException;

/**
 * intervals.icu could not be reached or answered with an error a later try may not hit.
 */
class IntervalsIcuUnreachableException extends RuntimeException
{
}
