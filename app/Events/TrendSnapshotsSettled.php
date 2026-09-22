<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after an athlete's queued trend snapshot repair has no dirty range left.
 */
final readonly class TrendSnapshotsSettled
{
    use Dispatchable;

    public function __construct(public int $userId)
    {
    }
}
