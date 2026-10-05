<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\HeldNotification;
use App\Models\User;
use App\Notifications\TestNotification;
use App\Services\Notifications\QuietHours;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * Holds each channel's send of a notification triggered inside quiet hours,
 * before any channel claims a delivery or writes the inbox. Returning false is
 * how a `NotificationSending` listener cancels a send.
 */
class HoldNotificationsInQuietHours
{
    public function handle(NotificationSending $event): ?false
    {
        if (! $event->notifiable instanceof User || $event->notification instanceof TestNotification || ! QuietHours::inEffect()) {
            return null;
        }

        HeldNotification::query()->create([
            'user_id' => $event->notifiable->id,
            'channel' => $event->channel,
            'notification' => serialize($event->notification),
            'held_at' => now(),
        ]);

        return false;
    }
}
