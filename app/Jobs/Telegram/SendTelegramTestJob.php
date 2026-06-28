<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramReplies;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends the one-off "test notification" from the Aku page so a user can confirm
 * their Telegram link works without waiting for a run. No-ops if the connection
 * is gone or revoked by the time the job runs.
 */
class SendTelegramTestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $userId)
    {
    }

    public function handle(TelegramClient $client): void
    {
        $connection = User::query()->find($this->userId)?->telegramConnection;
        if ($connection === null || $connection->isRevoked()) {
            return;
        }

        $client->sendMessage($connection->chat_id, TelegramReplies::test());
    }
}
