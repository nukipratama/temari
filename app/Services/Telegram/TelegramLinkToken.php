<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\Telegram\Exceptions\TelegramLinkTokenException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Mints and verifies the deep-link token that carries the logged-in user's
 * identity through Telegram's `/start <token>` payload. The token is HMAC-signed
 * with APP_KEY and carries an embedded TTL, so the bulk of the pending-link state
 * needs no storage. See the account-linking ADR.
 *
 * Telegram caps the `start` payload at 64 chars and allows only `[A-Za-z0-9_-]`,
 * so the token is a compact base64url of `user_id|expires_at` plus a truncated
 * HMAC, NOT an encrypted blob (which blows past 64 chars).
 *
 * Single use: once a token has linked an account, {@see self::consume()} marks it
 * spent in the cache (auto-expiring at the token's own TTL) so a leaked link
 * can't be replayed to re-bind the account within the TTL window.
 */
class TelegramLinkToken
{
    private const int TTL_SECONDS = 3600;

    /** Bytes of the HMAC kept; 16 (128-bit) is ample for a 1-hour single-use link. */
    private const int SIGNATURE_BYTES = 16;

    public function mint(int $userId): string
    {
        $body = $userId . '|' . Carbon::now()->addSeconds(self::TTL_SECONDS)->timestamp;

        return $this->base64UrlEncode($body . $this->sign($body));
    }

    /**
     * Resolve the user id a `/start` token points at.
     *
     * @throws TelegramLinkTokenException expired==true when the token verified
     *                                    but its TTL has passed or it was already
     *                                    consumed; expired==false when it is
     *                                    malformed / tampered.
     */
    public function userId(string $token): int
    {
        [$userId, $expiresAt] = $this->verify($token);

        if ($expiresAt < Carbon::now()->timestamp) {
            throw new TelegramLinkTokenException('Telegram link token has expired.', expired: true);
        }

        if (Cache::has($this->consumedKey($token))) {
            throw new TelegramLinkTokenException('Telegram link token was already used.', expired: true);
        }

        return $userId;
    }

    /**
     * Mark a token spent so it can't link again. TTL matches the token's own
     * expiry, so the marker self-cleans once the token would have expired anyway.
     */
    public function consume(string $token): void
    {
        [, $expiresAt] = $this->verify($token);

        $ttl = $expiresAt - Carbon::now()->getTimestamp();
        if ($ttl > 0) {
            Cache::put($this->consumedKey($token), true, $ttl);
        }
    }

    /**
     * Decode + authenticate a token, returning [user_id, expires_at]. Throws on a
     * malformed or tampered token (signature/format), but does NOT check expiry or
     * consumption — callers layer those on.
     *
     * @return array{0: int, 1: int}
     */
    private function verify(string $token): array
    {
        $raw = $this->base64UrlDecode($token);
        if ($raw === false || strlen($raw) <= self::SIGNATURE_BYTES) {
            throw new TelegramLinkTokenException('Telegram link token is malformed.');
        }

        $body = substr($raw, 0, -self::SIGNATURE_BYTES);
        $signature = substr($raw, -self::SIGNATURE_BYTES);
        if (! hash_equals($this->sign($body), $signature)) {
            throw new TelegramLinkTokenException('Telegram link token signature is invalid.');
        }

        $parts = explode('|', $body);
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            throw new TelegramLinkTokenException('Telegram link token payload is malformed.');
        }

        return [(int) $parts[0], (int) $parts[1]];
    }

    private function consumedKey(string $token): string
    {
        return 'telegram-link-used:' . hash('sha256', $token);
    }

    private function sign(string $body): string
    {
        return substr(hash_hmac('sha256', $body, (string) config('app.key'), binary: true), 0, self::SIGNATURE_BYTES);
    }

    private function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $token): string|false
    {
        $padded = str_pad($token, intdiv(strlen($token) + 3, 4) * 4, '=');

        return base64_decode(strtr($padded, '-_', '+/'), strict: true);
    }
}
