<?php

declare(strict_types=1);

namespace App\Enums;

enum StravaSyncSource: string
{
    case Poll = 'poll';
    case Webhook = 'webhook';
    case Manual = 'manual';
}
