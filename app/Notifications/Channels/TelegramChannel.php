<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Throwable;
use App\Jobs\Telegram\Concerns\RevokesConnectionOnPermanentFailure;
use App\Models\User;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a {@see TelegramMessage} for any notification that implements
 * `toTelegram()`. Lifted from the retired SendTelegramNotificationJob: it keeps
 * the once-only `telegram_deliveries` claim (so a queued retry is idempotent),
 * the photo-vs-text send, and the revoke-on-permanent-failure behaviour.
 *
 * A message with a null `deliveryKey` (streak / test) skips the claim entirely.
 * A `force` message (manual push) skips the claim CHECK — so a resend always
 * goes out — but records the claim on success, so a later automatic notification
 * for the same row (e.g. a "Baca ulang" re-analysis) is deduped against it.
 */
class TelegramChannel
{
    use RevokesConnectionOnPermanentFailure;

    public function __construct(private readonly TelegramClient $client)
    {
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

        // Automatic (keyed, non-force) sends claim before delivering; insertOrIgnore
        // is atomic on the unique analysis_id, so a racing retry that already claimed
        // it gets 0 rows and bails before re-sending.
        if ($message->deliveryKey !== null && ! $message->force && ! $this->claimDelivery($message->deliveryKey)) {
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

        // A manual push records the claim after a successful send (the automatic
        // path already claimed before sending), so a later automatic notification
        // for the same row is deduped. Best-effort and outside the deliver try: a
        // claim hiccup must not be misread as a send failure (the message already
        // went out) nor trigger a duplicate on retry.
        if ($message->force && $message->deliveryKey !== null) {
            $this->recordForcedClaim($message->deliveryKey);
        }
    }

    /**
     * A blocked bot / gone chat / bad token is non-retryable: mark the connection
     * dead (like a Strava revocation) and stop. A force push has no claim to
     * dedupe a retry, so it is one-shot: log and stop. The automatic path releases
     * its claim and rethrows so the queued notification's retry can resend.
     */
    private function handleFailure(Throwable $e, User $notifiable, TelegramMessage $message): void
    {
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

        if ($message->deliveryKey !== null) {
            DB::table('telegram_deliveries')->where('analysis_id', $message->deliveryKey)->delete();
        }

        throw $e;
    }

    /**
     * Claim the delivery before sending. Returns false when the row was already
     * claimed (a racing retry), so the caller bails instead of re-sending.
     */
    private function claimDelivery(int $deliveryKey): bool
    {
        $claimed = DB::table('telegram_deliveries')->insertOrIgnore([
            'analysis_id' => $deliveryKey,
            'created_at' => now(),
        ]);

        return $claimed !== 0;
    }

    private function recordForcedClaim(int $deliveryKey): void
    {
        try {
            $this->claimDelivery($deliveryKey);
        } catch (Throwable $e) {
            Log::warning('telegram.force_send.claim_failed', [
                'delivery_key' => $deliveryKey,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
