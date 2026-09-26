<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Throwable;
use App\Models\User;
use App\Services\Notifications\ChannelRouter;
use App\Services\Notifications\NotificationDeliveryClaim;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Wraps the package {@see WebPushChannel} with the shared per-(analysis, channel)
 * delivery claim, so a queued retry — or a fresh notify() for the same analysis
 * (a "Reread" re-analysis, ai:self-heal) — never double-pushes. Notifications
 * that expose no int `deliveryKey()` (streak / test) send without a claim.
 *
 * A notification whose `forcesDelivery()` is true — the manual "Send notification"
 * buttons — skips the claim and records it after a successful send, matching
 * {@see TelegramChannel}. On a hard send failure the claim is released so the
 * notification's retry can genuinely resend rather than being deduped against its
 * own half-done attempt; a forced send has no claim of its own to release.
 */
class IdempotentWebPushChannel
{
    private const string CHANNEL = 'webpush';

    public function __construct(
        private readonly WebPushChannel $channel,
        private readonly NotificationDeliveryClaim $claim,
        private readonly ChannelRouter $router,
    ) {
    }

    public function send(User $notifiable, Notification $notification): void
    {
        $notifiable = $this->router->eligibleUserFor($notifiable, self::class);
        if ($notifiable === null) {
            return;
        }

        $rawKey = method_exists($notification, 'deliveryKey') ? $notification->deliveryKey() : null;
        $deliveryKey = is_int($rawKey) ? $rawKey : null;
        $force = method_exists($notification, 'forcesDelivery') && $notification->forcesDelivery();

        $claimVersion = null;
        if ($deliveryKey !== null && ! $force) {
            $claimVersion = $this->claim->claim($deliveryKey, self::CHANNEL);
            if ($claimVersion === null) {
                return;
            }
        }

        try {
            $this->channel->send($notifiable, $notification);
        } catch (Throwable $e) {
            if ($deliveryKey !== null) {
                $this->record(
                    fn (): bool => $claimVersion === null
                        ? $this->claim->recordForcedFailed($deliveryKey, self::CHANNEL, $e->getMessage())
                        : $this->claim->markFailed($deliveryKey, self::CHANNEL, $claimVersion, $e->getMessage()),
                    $deliveryKey,
                    $claimVersion,
                );
            }

            throw $e;
        }

        if ($deliveryKey !== null) {
            $this->record(
                fn (): bool => $claimVersion === null
                    ? $this->claim->recordForcedSent($deliveryKey, self::CHANNEL)
                    : $this->claim->markSent($deliveryKey, self::CHANNEL, $claimVersion),
                $deliveryKey,
                $claimVersion,
            );
        }
    }

    /** @param callable(): bool $write */
    private function record(callable $write, int $deliveryKey, ?int $claimVersion): void
    {
        try {
            if (! $write() && $claimVersion !== null) {
                Log::info('webpush.delivery_record.fenced', [
                    'delivery_key' => $deliveryKey,
                    'claim_version' => $claimVersion,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('webpush.delivery_record.failed', [
                'delivery_key' => $deliveryKey,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
