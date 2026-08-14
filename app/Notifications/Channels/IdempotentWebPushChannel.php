<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Throwable;
use App\Services\Notifications\NotificationDeliveryClaim;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Wraps the package {@see WebPushChannel} with the shared per-(analysis, channel)
 * delivery claim, so a queued retry — or a fresh notify() for the same analysis
 * (a "Baca ulang" re-analysis, ai:self-heal) — never double-pushes. Notifications
 * that expose no int `deliveryKey()` (streak / test) send without a claim.
 *
 * A notification whose `forcesDelivery()` is true — the manual "Kirim notifikasi"
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
    ) {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        $rawKey = method_exists($notification, 'deliveryKey') ? $notification->deliveryKey() : null;
        $deliveryKey = is_int($rawKey) ? $rawKey : null;
        $force = method_exists($notification, 'forcesDelivery') && $notification->forcesDelivery();

        if ($deliveryKey !== null && ! $force && ! $this->claim->claim($deliveryKey, self::CHANNEL)) {
            return;
        }

        try {
            $this->channel->send($notifiable, $notification);
        } catch (Throwable $e) {
            if ($deliveryKey !== null) {
                $this->record(fn () => $this->claim->markFailed($deliveryKey, self::CHANNEL, $e->getMessage()), $deliveryKey);
            }

            throw $e;
        }

        if ($deliveryKey !== null) {
            $this->record(fn () => $this->claim->markSent($deliveryKey, self::CHANNEL), $deliveryKey);
        }
    }

    private function record(callable $write, int $deliveryKey): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            Log::warning('webpush.delivery_record.failed', [
                'delivery_key' => $deliveryKey,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
