<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a browser push subscription before it is stored. The `endpoint` is a
 * URL the server later POSTs the encrypted payload to, so it is the SSRF surface:
 * it must be HTTPS to one of the known push services, never an arbitrary or
 * internal host. The p256dh / auth keys are the client's ECDH public key and
 * auth secret (base64url), bounded in length.
 */
class StorePushSubscriptionRequest extends FormRequest
{
    /**
     * Host suffixes of the browser push services. A subscription endpoint from
     * PushManager.subscribe() is always one of these; anything else is rejected
     * so the send path can't be pointed at an internal or attacker host.
     */
    private const array ALLOWED_HOST_SUFFIXES = [
        '.push.apple.com',            // Safari / iOS
        '.notify.windows.com',        // Edge / Windows
        '.push.services.mozilla.com', // Firefox
    ];

    /**
     * @var array<int, string>
     */
    private const array ALLOWED_HOSTS = [
        'fcm.googleapis.com',                  // Chrome / Edge (FCM)
        'updates.push.services.mozilla.com',   // Firefox autopush
    ];

    public function authorize(): bool
    {
        // Route is behind auth; the subscription is always tied to $request->user()
        // in the controller, never to a request-supplied id.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:500', $this->allowedPushEndpoint()],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ];
    }

    private function allowedPushEndpoint(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! $this->isAllowedPushEndpoint($value)) {
                $fail('That push endpoint is not one we recognise.');
            }
        };
    }

    private function isAllowedPushEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return false;
        }

        $host = mb_strtolower($parts['host']);

        // Reject IP-literal hosts outright — a push service is always a hostname,
        // and an IP host is the classic SSRF-to-internal-address vector.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if (in_array($host, self::ALLOWED_HOSTS, true)) {
            return true;
        }
        return array_any(self::ALLOWED_HOST_SUFFIXES, fn ($suffix) => str_ends_with($host, (string) $suffix));
    }
}
