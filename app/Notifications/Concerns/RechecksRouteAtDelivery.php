<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Notifications\Channels\InAppChannel;
use App\Services\Notifications\ChannelRouter;
use App\Services\Notifications\NotificationDeliveryClaim;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Re-runs `via()` against the current user before each outbound send, since
 * Laravel evaluates it only at enqueue; the inbox entry is always kept. A
 * notification that sets `$staleAfter` is also skipped once
 * delivery passes that instant, judged as of `$heldAt` when it was held in
 * quiet hours.
 */
trait RechecksRouteAtDelivery
{
    public ?CarbonInterface $staleAfter = null;

    public ?CarbonInterface $heldAt = null;

    public function shouldSend(User $notifiable, string $channel): bool
    {
        if ($channel === InAppChannel::class) {
            return true;
        }

        if ($this->isStale()) {
            $this->skipAsStale($notifiable, $channel);

            return false;
        }

        $currentUser = $notifiable->fresh();

        return $currentUser !== null && in_array($channel, $this->via($currentUser), true);
    }

    private function isStale(): bool
    {
        return $this->staleAfter !== null && ($this->heldAt ?? now())->greaterThan($this->staleAfter);
    }

    private function skipAsStale(User $notifiable, string $channel): void
    {
        Log::info('notifications.stale_skipped', [
            'type' => static::class,
            'channel' => $channel,
            'user_id' => $notifiable->id,
        ]);

        $deliveryKey = method_exists($this, 'deliveryKey') ? $this->deliveryKey() : null;
        if (is_int($deliveryKey)) {
            app(NotificationDeliveryClaim::class)->recordForcedFailed(
                $deliveryKey,
                ChannelRouter::deliveryChannel($channel),
                'Skipped as stale at delivery.',
            );
        }
    }
}
