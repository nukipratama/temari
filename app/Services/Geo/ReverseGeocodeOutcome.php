<?php

declare(strict_types=1);

namespace App\Services\Geo;

enum ReverseGeocodeOutcome
{
    case NoAddress;
    case TransientFailure;
}
