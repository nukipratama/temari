<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use NotificationChannels\WebPush\PushSubscription;

#[Signature('notifications:prune-push-subscriptions')]
#[Description('Delete push subscriptions whose installed app has not reported in for 60 days')]
class PrunePushSubscriptionsCommand extends Command
{
    public const int UNSEEN_DAYS = 60;

    public function handle(): int
    {
        $deleted = PushSubscription::query()->where('last_seen_at', '<', now()->subDays(self::UNSEEN_DAYS))->delete();

        $this->info("Pruned {$deleted} push subscriptions unseen for ".self::UNSEEN_DAYS.' days.');

        return self::SUCCESS;
    }
}
