<?php

declare(strict_types=1);

namespace App\Exceptions\Weather;

use RuntimeException;

/** Open-Meteo is rate-limiting or unreachable, so the rest of a batch would fail the same way. */
class OpenMeteoUnavailableException extends RuntimeException
{
}
