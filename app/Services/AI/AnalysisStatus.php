<?php

declare(strict_types=1);

namespace App\Services\AI;

enum AnalysisStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
}
