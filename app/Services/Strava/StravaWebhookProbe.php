<?php

declare(strict_types=1);

namespace App\Services\Strava;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Probes the app's own public webhook callback exactly as Strava's verify
 * handshake does: a GET carrying hub.mode / hub.verify_token / hub.challenge,
 * expecting the challenge echoed back as {"hub.challenge": ...}. Shared by the
 * subscribe command (pre-flight) and the doctor command (health check) so the
 * handshake — including the dotted-key parsing gotcha — lives in one place.
 */
class StravaWebhookProbe
{
    /**
     * @return array{passed: bool, status: int, detail: string}
     */
    public function probe(string $callbackUrl, string $verifyToken): array
    {
        $challenge = 'probe-'.bin2hex(random_bytes(6));

        try {
            $response = Http::timeout(10)->get($callbackUrl, [
                'hub.mode' => 'subscribe',
                'hub.verify_token' => $verifyToken,
                'hub.challenge' => $challenge,
            ]);
        } catch (Throwable $e) {
            return ['passed' => false, 'status' => 0, 'detail' => $e->getMessage()];
        }

        // The body key is the literal "hub.challenge" (a flat key with a dot),
        // so reach into the decoded array directly — json('hub.challenge') would
        // misread the dot as nesting.
        $body = $response->json();
        $echoed = is_array($body) ? ($body['hub.challenge'] ?? null) : null;

        return [
            'passed' => $response->status() === 200 && $echoed === $challenge,
            'status' => $response->status(),
            'detail' => (string) Str::limit($response->body(), 300),
        ];
    }
}
