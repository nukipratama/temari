<?php

declare(strict_types=1);

namespace App\Services\Telegram\Exceptions;

use RuntimeException;

/** Raised when a /start comes from a chat another account holds an active link on. */
class TelegramChatLinkedElsewhereException extends RuntimeException
{
}
