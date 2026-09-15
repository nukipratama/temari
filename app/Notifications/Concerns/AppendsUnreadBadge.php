<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\InboxNotification;

/**
 * Shared by every push-carrying notification: appends the current inbox
 * unread count to the WebPush `data` payload, which is what the service
 * worker (public/sw.js) reads to keep the PWA app-icon badge in sync with
 * the inbox.
 */
trait AppendsUnreadBadge
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withUnreadBadge(array $data, int $userId): array
    {
        return [...$data, 'unread' => InboxNotification::unreadCountFor($userId)];
    }
}
