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
     * @param  int|null  $usedByUserId  The token's user when it was already consumed.
     */
    public function __construct(
        string $message = '',
        public readonly bool $expired = false,
        public readonly ?int $usedByUserId = null,
    ) {
        parent::__construct($message);
    }
}
