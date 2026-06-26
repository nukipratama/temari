<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\Telegram\Exceptions\TelegramLinkTokenException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinkToken;
use App\Services\Telegram\TelegramReplies;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The shared linking core both delivery modes feed: the prod webhook
 * ({@see \App\Http\Controllers\Telegram\TelegramWebhookController}) and the
 * dev `telegram:listen` long-poll. Resolves a `/start <token>` to a user and
 * stores their chat id, or handles `/stop`. See the account-linking ADR.
 */
class HandleTelegramUpdateJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    /**
     * @param  array<string, mixed>  $update  One raw Telegram update payload.
     */
    public function __construct(public readonly array $update)
    {
    }

    public function handle(TelegramClient $client, TelegramLinkToken $linkToken): void
    {
        $message = $this->update['message'] ?? null;
        if (! is_array($message)) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        if (! is_int($chatId)) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if (str_starts_with($text, '/start')) {
            $this->handleStart($client, $linkToken, $chatId, $text, $message);

            return;
        }

        if ($text === '/stop') {
            $this->handleStop($client, $chatId);

            return;
        }

        $client->sendMessage($chatId, TelegramReplies::generic());
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleStart(
        TelegramClient $client,
        TelegramLinkToken $linkToken,
        int $chatId,
        string $text,
        array $message,
    ): void {
        $token = trim((string) substr($text, strlen('/start')));

        if ($token === '') {
            $client->sendMessage($chatId, TelegramReplies::generic());

            return;
        }

        try {
            $userId = $linkToken->userId($token);
        } catch (TelegramLinkTokenException $e) {
            $client->sendMessage($chatId, $e->expired ? TelegramReplies::expired() : TelegramReplies::generic());

            return;
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            $client->sendMessage($chatId, TelegramReplies::generic());

            return;
        }

        // Clear any revoked row from another user that still holds this chat_id.
        // Without this, the unique constraint on chat_id would cause the
        // updateOrCreate below to throw when a Telegram account previously
        // linked to user A (then /stop-ped) tries to link to user B.
        TelegramConnection::query()
            ->where('chat_id', $chatId)
            ->where('user_id', '!=', $user->id)
            ->whereNotNull('revoked_at')
            ->delete();

        TelegramConnection::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'chat_id' => $chatId,
                'username' => $message['from']['username'] ?? null,
                'revoked_at' => null,
            ],
        );

        $client->sendMessage($chatId, TelegramReplies::welcome((string) $user->name));

        // Single use: burn the token so a leaked link can't re-bind the account
        // within its TTL. Done after the welcome so a failed send retries cleanly.
        $linkToken->consume($token);
    }

    private function handleStop(TelegramClient $client, int $chatId): void
    {
        $connection = TelegramConnection::query()->where('chat_id', $chatId)->first();
        $connection?->markRevoked();

        $client->sendMessage($chatId, TelegramReplies::disconnected());
    }
}
