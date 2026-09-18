<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Services\AI\MaintainerAlerter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one maintainer alert to every admin's Telegram chat. Every
 * {@see MaintainerAlerter} alert dispatches this rather than calling
 * {@see \App\Services\Telegram\TelegramClient} inline, so a slow or
 * unreachable Telegram never blocks the caller — including a FrankenPHP
 * worker, for the alerts reachable from the Inertia shared-prop path.
 */
class SendMaintainerAlertJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $message)
    {
    }

    public function handle(MaintainerAlerter $alerter): void
    {
        $alerter->sendToAdmins($this->message);
    }
}
