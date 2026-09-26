<?php

declare(strict_types=1);

namespace App\Enums;

enum StravaGrantReleaseStatus: string
{
    case Released = 'released';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Stale = 'stale';
}
