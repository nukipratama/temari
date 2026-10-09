<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Ops\MaintainerAlerter;
use Laravel\Horizon\Events\LongWaitDetected;

class AlertOnLongQueueWait
{
    public function __construct(private readonly MaintainerAlerter $alerter)
    {
    }

    public function handle(LongWaitDetected $event): void
    {
        $this->alerter->queueWaitLong($event->connection, $event->queue, (int) $event->seconds);
    }
}
