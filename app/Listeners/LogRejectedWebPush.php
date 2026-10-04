<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\Events\NotificationFailed;

/**
 * Leaves a trace for every push a push service rejects. The endpoint path is a
 * device capability and the keys decrypt the payload, so only the host is logged.
 */
class LogRejectedWebPush
{
    private const int REASON_LIMIT = 200;

    public function handle(NotificationFailed $event): void
    {
        $subscription = $event->subscription;

        Log::warning('webpush.rejected', [
            'status' => $event->report->getResponse()?->getStatusCode(),
            'reason' => Str::limit(self::withoutUrlPaths($event->report->getReason()), self::REASON_LIMIT),
            'push_host' => parse_url((string) $subscription->endpoint, PHP_URL_HOST),
            'subscription_id' => $subscription->getKey(),
            'user_id' => $subscription->subscribable_id,
        ]);
    }

    private static function withoutUrlPaths(string $reason): string
    {
        return (string) preg_replace('~(https?://[^/\s`\'"]+)[^\s`\'"]*~i', '$1', $reason);
    }
}
