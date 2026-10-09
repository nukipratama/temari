<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Throwable;
use App\Jobs\Telegram\Concerns\RevokesConnectionOnPermanentFailure;
use App\Models\User;
use App\Services\AI\MaintainerAlerter;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Notifications\ChannelRouter;
use App\Services\Notifications\NotificationDeliveryClaim;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a {@see TelegramMessage} for any notification that implements
 * `toTelegram()`. Keeps delivery once-only (so a queued retry is idempotent)
 * and the revoke-on-permanent-failure behaviour. The
 * claim is held on the shared {@see NotificationDeliveryClaim} keyed by
 * (analysis, channel).
 *
 * A message with a null `deliveryKey` (streak / test) skips the claim entirely.
 */
class TelegramChannel
{
    use RevokesConnectionOnPermanentFailure;

    private const string CHANNEL = 'telegram';

    public function __construct(
        private readonly TelegramClient $client,
        private readonly NotificationDeliveryClaim $claim,
        private readonly ChannelRouter $router,
        private readonly MaintainerAlerter $alerter,
    ) {
    }

    public function send(User $notifiable, Notification $notification): void
    {
        $notifiable = $this->router->eligibleUserFor($notifiable, self::class);
        if ($notifiable === null) {
            return;
        }

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

        // Keyed sends claim before delivering; the claim is atomic on the unique
        // (analysis_id, channel) pair, so a racing retry that already claimed it
        // bails before re-sending.
        $claimVersion = null;
        if ($message->deliveryKey !== null) {
            $claimVersion = $this->claim->claim($message->deliveryKey, self::CHANNEL);
            if ($claimVersion === null) {
                return;
            }
        }

        try {
            $this->client->sendMessage($connection->chat_id, $message->text);
        } catch (Throwable $e) {
            $this->handleFailure($e, $notifiable, $message, $claimVersion);

            return;
        }

        // Best-effort and outside the deliver try: a bookkeeping hiccup must not be
        // misread as a send failure (the message already went out) nor trigger a
        // duplicate on retry.
        $deliveryKey = $message->deliveryKey;
        if ($deliveryKey !== null) {
            $this->record(
                fn (): bool => $this->claim->markSent($deliveryKey, self::CHANNEL, $claimVersion),
                $deliveryKey,
                $claimVersion,
            );
        }
    }

    /**
     * A blocked bot / gone chat revokes the connection (like a Strava revocation)
     * and stops. A bad bot token (401/404) alerts the maintainer and keeps the link,
     * and a message Telegram rejects (other 4xx) is logged and kept too, without a
     * retry. A keyed send that lost its connection may still have been accepted, so
     * it is abandoned rather than retried, as a crashed claim is. Everything else
     * rethrows so the queued notification's retry can resend, which the failed claim
     * now permits.
     */
    private function handleFailure(Throwable $e, User $notifiable, TelegramMessage $message, ?int $claimVersion): void
    {
        $deliveryKey = $message->deliveryKey;
        if ($deliveryKey !== null && $claimVersion !== null) {
            if ($e instanceof TelegramApiException && $e->connectionFailed) {
                $this->record(
                    fn (): bool => $this->claim->markAbandoned($deliveryKey, self::CHANNEL, $claimVersion, $e->getMessage()),
                    $deliveryKey,
                    $claimVersion,
                );

                return;
            }

            $this->record(
                fn (): bool => $this->claim->markFailed($deliveryKey, self::CHANNEL, $claimVersion, $e->getMessage()),
                $deliveryKey,
                $claimVersion,
            );
        }

        if ($e instanceof TelegramApiException) {
            if ($this->isChatSpecificFailure($e)) {
                $notifiable->telegramConnection?->markRevoked();

                return;
            }

            if ($this->isBotConfigurationFailure($e)) {
                $this->alerter->telegramBotRejected((int) $e->status);
            } elseif ($this->isRejectedMessage($e)) {
                Log::warning('telegram.send.rejected', [
                    'delivery_key' => $message->deliveryKey,
                    'reason' => $e->getMessage(),
                ]);

                return;
            }
        }

        throw $e;
    }

    /** @param callable(): bool $write */
    private function record(callable $write, int $deliveryKey, int $claimVersion): void
    {
        try {
            if (! $write()) {
                Log::info('telegram.delivery_record.fenced', [
                    'delivery_key' => $deliveryKey,
                    'claim_version' => $claimVersion,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('telegram.delivery_record.failed', [
                'delivery_key' => $deliveryKey,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
