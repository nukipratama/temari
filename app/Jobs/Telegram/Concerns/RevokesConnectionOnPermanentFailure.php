<?php

declare(strict_types=1);

namespace App\Jobs\Telegram\Concerns;

use App\Services\Telegram\Exceptions\TelegramApiException;

trait RevokesConnectionOnPermanentFailure
{
    /**
     * A 4xx other than 429 (rate limit) is permanent: the bot is blocked (403),
     * the chat is gone, or the token is bad. Retrying it just churns the queue
     * and pollutes failed_jobs, so treat it like a Strava revocation.
     */
    private function isPermanentTelegramFailure(TelegramApiException $e): bool
    {
        return $e->status !== null && $e->status >= 400 && $e->status < 500 && $e->status !== 429;
    }
}
