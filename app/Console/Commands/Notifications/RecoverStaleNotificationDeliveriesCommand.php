<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Jobs\Notifications\RetryStaleWebPushNotificationJob;
use App\Services\Notifications\NotificationDeliveryClaim;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:recover-deliveries')]
#[Description('Re-arm stale web pushes and abandon ambiguous Telegram deliveries')]
class RecoverStaleNotificationDeliveriesCommand extends Command
{
    public function handle(NotificationDeliveryClaim $claim): int
    {
        $recovered = $claim->recoverStale();
        foreach ($recovered['webpush_rearmed'] as $analysisId) {
            RetryStaleWebPushNotificationJob::dispatch($analysisId);
        }

        $this->info('Recovered stale deliveries: '.count($recovered['webpush_rearmed'])." web push re-armed, {$recovered['telegram_abandoned']} Telegram abandoned.");

        return self::SUCCESS;
    }
}
