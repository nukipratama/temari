<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Jobs\Notifications\RetryStaleWebPushNotificationJob;
use App\Services\Notifications\NotificationDeliveryClaim;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:recover-deliveries')]
#[Description('Retry stale web pushes and abandon ambiguous Telegram deliveries')]
class RecoverStaleNotificationDeliveriesCommand extends Command
{
    public function handle(NotificationDeliveryClaim $claim): int
    {
        $recovered = $claim->recoverStale();
        foreach ($recovered['webpush_retries'] as $retry) {
            RetryStaleWebPushNotificationJob::dispatch($retry['analysis_id'], $retry['claim_version']);
        }

        $this->info('Recovered stale deliveries: '.count($recovered['webpush_retries'])." web push retries queued, {$recovered['telegram_abandoned']} Telegram abandoned.");

        return self::SUCCESS;
    }
}
