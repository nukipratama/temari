<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Services\AI\MaintainerAlerter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Flushes the coalesced dead-letter window into one Telegram summary. Queued
 * (not called inline) so {@see MaintainerAlerter::deadLettered()} can delay it
 * — the delay is what gives later dead-letters in the same window a chance to
 * land before the flush reads the count.
 */
class FlushDeadLetterAlertJob implements ShouldQueue
{
    use Queueable;

    public function handle(MaintainerAlerter $alerter): void
    {
        $alerter->flushDeadLetterWindow();
    }
}
