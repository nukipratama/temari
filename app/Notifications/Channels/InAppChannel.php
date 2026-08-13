<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\Messages\InboxMessage;
use Illuminate\Notifications\Notification;

/**
 * Writes a notification into the user's inbox. Unlike Telegram and web push
 * there is nothing to wire up and nothing to mute, so {@see \App\Services\Notifications\ChannelRouter}
 * always includes this channel: the inbox is the durable record of what Temari
 * sent, and a muted record is a lost one.
 *
 * Idempotency is the row's own unique (user, dedupe key) pair rather than the
 * per-(analysis, channel) claim the outbound channels share, because streak and
 * unlock notifications have no analysis to key on. A message that supplies no
 * key falls back to the notification's id, which Laravel assigns before queuing
 * and therefore survives a retry unchanged.
 */
class InAppChannel
{
    public function send(User $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toInbox')) {
            return;
        }

        $message = $notification->toInbox($notifiable);
        if (! $message instanceof InboxMessage) {
            return;
        }

        InboxNotification::record($notifiable, $message, $message->dedupeKey ?? (string) $notification->id);
    }
}
