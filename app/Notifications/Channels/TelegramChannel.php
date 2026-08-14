<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Throwable;
use App\Jobs\Telegram\Concerns\RevokesConnectionOnPermanentFailure;
use App\Models\User;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Notifications\NotificationDeliveryClaim;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a {@see TelegramMessage} for any notification that implements
 * `toTelegram()`. Keeps delivery once-only (so a queued retry is idempotent),
 * the photo-vs-text send, and the revoke-on-permanent-failure behaviour. The
 * claim is held on the shared {@see NotificationDeliveryClaim} keyed by
 * (analysis, channel).
 *
 * A message with a null `deliveryKey` (streak / test) skips the claim entirely.
 * A `force` message (manual push) skips the claim CHECK — so a resend always
 * goes out — but records the outcome, so a later automatic notification for the
 * same row (e.g. a "Baca ulang" re-analysis) is deduped against a success.
 */
class TelegramChannel
{
    use RevokesConnectionOnPermanentFailure;

    private const string CHANNEL = 'telegram';

    public function __construct(
        private readonly TelegramClient $client,
        private readonly NotificationDeliveryClaim $claim,
    ) {
    }

    public function send(User $notifiable, Notification $notification): void
    {
        $connection = $notifiable->telegramConnection;
        if ($connection === null || $connection->isRevoked()) {
            return;
        }

        if (! method_exists($notification, 'toTelegram')) {
            return;
        }
        $message = $notification->toTelegram($notifiable);
        if (! $message instanceof TelegramMessage) {
            return;
        }

        // Automatic (keyed, non-force) sends claim before delivering; the claim is
        // atomic on the unique (analysis_id, channel) pair, so a racing retry that
        // already claimed it bails before re-sending.
        if ($message->deliveryKey !== null && ! $message->force && ! $this->claim->claim($message->deliveryKey, self::CHANNEL)) {
            return;
        }

        try {
            if ($message->photoPng !== null) {
                $this->client->sendPhoto($connection->chat_id, $message->photoPng, $message->text);
            } else {
                $this->client->sendMessage($connection->chat_id, $message->text);
            }
        } catch (Throwable $e) {
            $this->handleFailure($e, $notifiable, $message);

            return;
        }

        // Best-effort and outside the deliver try: a bookkeeping hiccup must not be
        // misread as a send failure (the message already went out) nor trigger a
        // duplicate on retry. A manual push has no claim of its own, so this is
        // also what dedupes a later automatic notification for the same row.
        if ($message->deliveryKey !== null) {
            $this->record(fn () => $this->claim->markSent($message->deliveryKey, self::CHANNEL), $message->deliveryKey);
        }
    }

    /**
     * A blocked bot / gone chat / bad token is non-retryable: mark the connection
     * dead (like a Strava revocation) and stop. A force push is one-shot: log and
     * stop. The automatic path rethrows so the queued notification's retry can
     * resend, which the failed claim now permits.
     */
    private function handleFailure(Throwable $e, User $notifiable, TelegramMessage $message): void
    {
        if ($message->deliveryKey !== null) {
            $this->record(
                fn () => $this->claim->markFailed($message->deliveryKey, self::CHANNEL, $e->getMessage()),
                $message->deliveryKey,
            );
        }

        if ($e instanceof TelegramApiException && $this->isPermanentTelegramFailure($e)) {
            $notifiable->telegramConnection?->markRevoked();

            return;
        }

        if ($message->force) {
            Log::warning('telegram.force_send.failed', [
                'delivery_key' => $message->deliveryKey,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        throw $e;
    }

    private function record(callable $write, int $deliveryKey): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            Log::warning('telegram.delivery_record.failed', [
                'delivery_key' => $deliveryKey,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
