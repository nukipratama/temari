<?php

declare(strict_types=1);

namespace App\Services\Telegram\Exceptions;

use RuntimeException;

class TelegramLinkTokenException extends RuntimeException
{
    /**
     * @param  bool  $expired  True when the token decrypted cleanly but its TTL
     *                         has passed (a "get a fresh link" case, distinct
     *                         from undecryptable garbage where this is false).
     */
    public function __construct(string $message = '', public readonly bool $expired = false)
    {
        parent::__construct($message);
    }
}
