<?php

declare(strict_types=1);

namespace App\Enums;

enum RaceIntent: string
{
    case Update = 'update';
    case New = 'new';
}
