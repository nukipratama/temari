<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\Messages\InboxMessage;

/**
 * Shared by every push-carrying notification: appends the current inbox
 * unread count to the WebPush `data` payload, which is what the service
 * worker (public/sw.js) reads to keep the PWA app-icon badge in sync with
 * the inbox, plus the id of this notification's own inbox row so tapping
 * the push can mark it read.
 */
trait AppendsUnreadBadge
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withUnreadBadge(array $data, User $notifiable): array
    {
        $inboxId = $this->inboxRowId($notifiable);

        return [
            ...$data,
            'unread' => InboxNotification::unreadCountFor($notifiable->id),
            ...($inboxId === null ? [] : ['inbox_id' => $inboxId]),
        ];
    }

    private function inboxRowId(User $notifiable): ?int
    {
        $message = method_exists($this, 'toInbox') ? $this->toInbox($notifiable) : null;
        if (! $message instanceof InboxMessage) {
            return null;
        }

        return InboxNotification::query()
            ->where('user_id', $notifiable->id)
            ->where('dedupe_key', InAppChannel::dedupeKey($this, $message))
            ->first(['id'])?->id;
    }
}
