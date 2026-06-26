<?php

declare(strict_types=1);

namespace App\Services\Telegram\Exceptions;

use RuntimeException;

class TelegramApiException extends RuntimeException
{
    /**
     * HTTP status Telegram returned, so callers can distinguish a transient
     * failure (retry) from a permanent one (drop). Null when the request never
     * got a response (transport failure).
     */
    public function __construct(string $message = '', public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
