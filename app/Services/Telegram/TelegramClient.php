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

    /**
     * Bounds DNS/TCP connection establishment on its own, so a resolver or
     * socket stall is capped independently of the total request timeout below.
     */
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    /** Total time budget for a call, on top of any long-poll timeout requested. */
    private const int TIMEOUT_SECONDS = 10;

    /**
     * Send a plain-text message to a chat. Returns nothing; throws on failure so
     * the caller (a job) decides retry vs drop from the status. $timeoutSeconds
     * replaces the default total timeout.
     */
    public function sendMessage(int $chatId, string $text, ?int $timeoutSeconds = null): void
    {
        $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ], timeoutSeconds: $timeoutSeconds);
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
     */
    private function call(string $method, array $params, int $longPollTimeout = 0, ?int $timeoutSeconds = null): mixed
    {
        $token = (string) config('services.telegram.bot_token');

        $http = $this->client($token, $longPollTimeout, $timeoutSeconds)->asJson();

        try {
            $response = $http->post('/' . $method, $params);
        } catch (ConnectionException $e) {
            // The bot token sits in the URL path, and Guzzle only redacts
            // user:pass@host — so a transport failure carries the live token in
            // its message, which callers log and persist.
            $reason = $token === ''
                ? $e->getMessage()
                : str_replace($token, '[redacted]', $e->getMessage());

            throw new TelegramApiException(
                "Telegram [{$method}] could not reach the API: {$reason}",
                connectionFailed: true,
            );
        }

        if ($response->failed() || $response->json('ok') !== true) {
            $description = (string) ($response->json('description') ?? $response->body());

            throw new TelegramApiException(
                "Telegram [{$method}] failed with status {$response->status()}: {$description}",
                $response->status(),
                $description,
            );
        }

        return $response->json('result');
    }

    /**
     * The base pending request for every Bot API call: an explicit connect
     * timeout bounds DNS/TCP setup, separately from the total timeout, so a
     * resolver or socket stall can never outlast either budget.
     */
    private function client(string $token, int $longPollTimeout, ?int $timeoutSeconds = null): PendingRequest
    {
        return Http::baseUrl(self::API_BASE_URL . '/bot' . $token)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout($timeoutSeconds ?? $longPollTimeout + self::TIMEOUT_SECONDS);
    }
}
