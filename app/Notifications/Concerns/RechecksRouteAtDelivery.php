<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Notifications\Channels\InAppChannel;

/**
 * Re-runs `via()` against the current user before each outbound send, since
 * Laravel evaluates it only at enqueue; the inbox entry is always kept.
 */
trait RechecksRouteAtDelivery
{
    public function shouldSend(User $notifiable, string $channel): bool
    {
        if ($channel === InAppChannel::class) {
            return true;
        }

        $currentUser = $notifiable->fresh();

        return $currentUser !== null && in_array($channel, $this->via($currentUser), true);
    }
}
