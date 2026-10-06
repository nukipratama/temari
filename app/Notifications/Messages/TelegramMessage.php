<?php

declare(strict_types=1);

namespace App\Notifications\Messages;

/**
 * The Telegram-channel payload a notification's `toTelegram()` returns: the text
 * body, an optional photo (post-run card PNG), and the idempotency metadata the
 * {@see \App\Notifications\Channels\TelegramChannel} needs.
 *
 * A null `deliveryKey` opts the message out of the once-only delivery claim
 * (streak / test nudges, which were never deduped).
 */
final readonly class TelegramMessage
{
    public function __construct(
        public string $text,
        public ?string $photoPng = null,
        public ?int $deliveryKey = null,
    ) {
    }
}
