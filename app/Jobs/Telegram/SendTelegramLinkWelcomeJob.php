<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramReplies;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTelegramLinkWelcomeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

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
