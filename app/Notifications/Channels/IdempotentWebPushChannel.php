<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Throwable;
use App\Exceptions\Notifications\TransientWebPushException;
use App\Models\User;
use App\Services\Notifications\ChannelRouter;
use App\Services\Notifications\NotificationDeliveryClaim;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Wraps the package {@see WebPushChannel} with the shared per-(analysis, channel)
 * delivery claim, so a queued retry — or a fresh notify() for the same analysis
 * (a "Reread" re-analysis, ai:self-heal) — never double-pushes. Notifications
 * that expose no int `deliveryKey()` (streak reminder, test, race tomorrow, race
 * outcome, Strava disconnected) send without a claim.
 *
 * The package reports each subscription's result instead of throwing. One accepted
 * subscription records `sent`. When none accepted, a 429, 5xx or network failure
 * throws {@see TransientWebPushException} so the queue retries, and any other
 * rejection (400, 403, 413, an expired 404/410) records `failed` with its status.
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

        $claimVersion = null;
        if ($deliveryKey !== null) {
            $claimVersion = $this->claim->claim($deliveryKey, self::CHANNEL);
            if ($claimVersion === null) {
                return;
            }
        }

        try {
            $rejection = self::permanentRejection($this->channel->send($notifiable, $notification));
        } catch (Throwable $e) {
            if ($deliveryKey !== null) {
                $this->recordFailed($deliveryKey, $claimVersion, $e->getMessage());
            }

            throw $e;
        }

        if ($deliveryKey === null) {
            return;
        }

        if ($rejection !== null) {
            $this->recordFailed($deliveryKey, $claimVersion, $rejection);

            return;
        }

        $this->record(
            fn (): bool => $this->claim->markSent($deliveryKey, self::CHANNEL, $claimVersion),
            $deliveryKey,
            $claimVersion,
        );
    }

    /**
     * @param  array<int, MessageSentReport>  $reports
     *
     * @throws TransientWebPushException
     */
    private static function permanentRejection(array $reports): ?string
    {
        $reports = collect($reports);
        if ($reports->isEmpty() || $reports->contains(fn (MessageSentReport $report): bool => $report->isSuccess())) {
            return null;
        }

        $statuses = $reports->map(fn (MessageSentReport $report): string => match (true) {
            $report->getResponse() === null => 'network error',
            $report->isSubscriptionExpired() => $report->getResponse()->getStatusCode().' expired',
            default => (string) $report->getResponse()->getStatusCode(),
        })->implode(', ');

        $transient = $reports->filter(self::isTransient(...));
        if ($transient->isNotEmpty()) {
            throw new TransientWebPushException(
                "Push service failed transiently ({$statuses}).",
                $transient->map(self::retryAfterSeconds(...))->filter(fn (?int $seconds): bool => $seconds !== null)->max(),
            );
        }

        return "Push service rejected the notification ({$statuses}).";
    }

    private static function isTransient(MessageSentReport $report): bool
    {
        $status = $report->getResponse()?->getStatusCode();

        return $status === null || $status === 429 || $status >= 500;
    }

    private static function retryAfterSeconds(MessageSentReport $report): ?int
    {
        $header = trim((string) $report->getResponse()?->getHeaderLine('Retry-After'));
        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $retryAt = strtotime($header);

        return $retryAt === false ? null : max(0, $retryAt - now()->getTimestamp());
    }

    private function recordFailed(int $deliveryKey, int $claimVersion, string $error): void
    {
        $this->record(
            fn (): bool => $this->claim->markFailed($deliveryKey, self::CHANNEL, $claimVersion, $error),
            $deliveryKey,
            $claimVersion,
        );
    }

    /** @param callable(): bool $write */
    private function record(callable $write, int $deliveryKey, int $claimVersion): void
    {
        try {
            if (! $write()) {
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
