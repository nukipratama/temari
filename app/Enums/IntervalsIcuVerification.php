<?php

declare(strict_types=1);

namespace App\Enums;

enum IntervalsIcuVerification
{
    case Verified;
    case Rejected;
    case Unreachable;
}
