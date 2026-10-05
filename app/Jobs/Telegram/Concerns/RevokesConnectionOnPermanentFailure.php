<?php

declare(strict_types=1);

namespace App\Jobs\Telegram\Concerns;

use App\Services\Telegram\Exceptions\TelegramApiException;

trait RevokesConnectionOnPermanentFailure
{
    private function isChatSpecificFailure(TelegramApiException $e): bool
    {
        $description = strtolower($e->description ?? '');

        return match ($e->status) {
            403 => str_contains($description, 'bot was blocked by the user')
                || str_contains($description, 'user is deactivated'),
            400 => str_contains($description, 'chat not found'),
            default => false,
        };
    }

    private function isBotConfigurationFailure(TelegramApiException $e): bool
    {
        return $e->status === 401 || $e->status === 404;
    }

    private function isRejectedMessage(TelegramApiException $e): bool
    {
        return $e->status !== null && $e->status >= 400 && $e->status < 500 && $e->status !== 429;
    }
}
