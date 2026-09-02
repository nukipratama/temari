<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\Telegram\Exceptions\TelegramApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TelegramClient
{
    private const string API_BASE_URL = 'https://api.telegram.org';

    /** Telegram's hard cap on a photo `caption` (characters). */
    private const int CAPTION_MAX = 1024;

    /**
     * Send a plain-text message to a chat. Returns nothing; throws on failure so
     * the caller (a job) decides retry vs drop from the status.
     */
    public function sendMessage(int $chatId, string $text): void
    {
        $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    /**
     * Send a photo to a chat by uploading the raw PNG bytes as multipart, so it
     * works without a publicly-fetchable URL. The caption is truncated to
     * Telegram's 1024-character cap. Throws on failure like {@see sendMessage}.
     */
    public function sendPhoto(int $chatId, string $photo, ?string $caption): void
    {
        // Multipart form fields are strings on the wire; cast so the chat id is
        // sent as "4242" rather than a bare int part.
        $params = ['chat_id' => (string) $chatId];
        if ($caption !== null && $caption !== '') {
            $params['caption'] = $this->truncateCaption($caption);
        }

        $this->call('sendPhoto', $params, request: fn (PendingRequest $http): PendingRequest => $http
            ->asMultipart()
            ->attach('photo', $photo, 'card.png'));
    }

    /** Cut a caption to Telegram's character cap, marking a truncation with an ellipsis. */
    private function truncateCaption(string $caption): string
    {
        if (mb_strlen($caption) <= self::CAPTION_MAX) {
            return $caption;
        }

        return mb_substr($caption, 0, self::CAPTION_MAX - 1) . '…';
    }

    /**
     * Register the push webhook with Telegram. The $secret is echoed back in the
     * X-Telegram-Bot-Api-Secret-Token header on every delivery so we can verify
     * the request really came from Telegram.
     */
    public function setWebhook(string $url, string $secret): void
    {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
        ]);
    }

    /**
     * Long-poll for queued updates (pull delivery, used by `telegram:listen` in
     * dev so no public URL is needed). $offset acks everything below it; $timeout
     * is how long Telegram holds the request open waiting for an update.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeout): array
    {
        /** @var array<int, array<string, mixed>> $result */
        $result = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
        ], $timeout);

        return $result;
    }

    /**
     * POST a Bot API method and return its `result` payload. Telegram answers
     * 2xx with `{"ok": true, "result": ...}`; anything else (HTTP error or
     * `ok: false`) becomes a TelegramApiException carrying the status.
     *
     * @param  array<string, mixed>  $params
     * @param  int  $longPollTimeout  Seconds Telegram may hold the request open;
     *                                the HTTP client timeout is set above it.
     * @param  (callable(PendingRequest): PendingRequest)|null  $request  Customises
     *         the pending request (e.g. multipart upload); defaults to a JSON body.
     */
    private function call(string $method, array $params, int $longPollTimeout = 0, ?callable $request = null): mixed
    {
        $token = (string) config('services.telegram.bot_token');

        $http = Http::baseUrl(self::API_BASE_URL . '/bot' . $token)->timeout($longPollTimeout + 10);
        $http = $request !== null ? $request($http) : $http->asJson();

        try {
            $response = $http->post('/' . $method, $params);
        } catch (ConnectionException $e) {
            throw new TelegramApiException(
                "Telegram [{$method}] could not reach the API: {$e->getMessage()}",
            );
        }

        if ($response->failed() || $response->json('ok') !== true) {
            $description = (string) ($response->json('description') ?? $response->body());

            throw new TelegramApiException(
                "Telegram [{$method}] failed with status {$response->status()}: {$description}",
                $response->status(),
            );
        }

        return $response->json('result');
    }
}
