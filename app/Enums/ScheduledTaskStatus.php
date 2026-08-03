<?php

declare(strict_types=1);

namespace App\Enums;

enum ScheduledTaskStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
}
