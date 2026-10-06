<?php

declare(strict_types=1);

namespace App\Services\Inertia;

use App\Models\InboxNotification;
use App\Models\User;
use App\Support\SharedPropCacheKey;
use Closure;

/**
 * The unread inbox count. It needs no reachability question: the in-app
 * channel always delivers.
 *
 * Every prop is returned as a closure, so Inertia skips the work entirely on a
 * partial reload that did not ask for that key.
 */
final readonly class NotificationProps
{
    /**
     * @return array<string, Closure>
     */
    public function forUser(?User $user): array
    {
        return [
            'unreadNotifications' => fn (): int => $this->unreadNotificationsFor($user),
        ];
    }

    /**
     * How many inbox rows the user has not opened, for the badge on the bell.
     * Busted on every inbox write and every read, so the TTL is a safety net.
     */
    private function unreadNotificationsFor(?User $user): int
    {
        if ($user === null) {
            return 0;
        }

        return SharedPropCacheKey::UnreadNotifications->remember(
            $user->id,
            fn (): int => InboxNotification::unreadCountFor($user->id),
        );
    }
}
