<?php

declare(strict_types=1);

namespace App\Enums;

enum PerformanceEvidenceKind: string
{
    case Race = 'race';
    case Test = 'test';
}
