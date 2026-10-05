<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use Carbon\CarbonInterface;

trait SetsWebPushExpiry
{
    private function secondsUntil(CarbonInterface $deadline): int
    {
        return max(1, (int) now()->diffInSeconds($deadline, false));
    }
}
