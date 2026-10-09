<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramReplies;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Foundation\Queue\Queueable;

#[Backoff([30, 120])]
#[Tries(3)]
class SendTelegramLinkWelcomeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $chatId,
        public readonly string $name,
    ) {
    }

    public function handle(TelegramClient $client): void
    {
        $client->sendMessage($this->chatId, TelegramReplies::welcome($this->name));
    }
}
