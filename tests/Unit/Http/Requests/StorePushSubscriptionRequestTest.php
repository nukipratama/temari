<?php

declare(strict_types=1);

use App\Http\Requests\StorePushSubscriptionRequest;
use Illuminate\Support\Facades\Validator;

/**
 * @param  array<string, mixed>  $data
 */
function passesSubscription(array $data): bool
{
    return Validator::make($data, new StorePushSubscriptionRequest()->rules())->passes();
}

/**
 * @return array{endpoint: string, keys: array{p256dh: string, auth: string}}
 */
function subscription(string $endpoint): array
{
    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-token']];
}

it('accepts the known push-service endpoints', function (string $endpoint): void {
    expect(passesSubscription(subscription($endpoint)))->toBeTrue();
})->with([
    'Chrome / FCM' => 'https://fcm.googleapis.com/fcm/send/abc123',
    'Safari / Apple' => 'https://web.push.apple.com/Qz1abc',
    'Edge / Windows' => 'https://sea1.notify.windows.com/w/?token=abc',
    'Firefox' => 'https://updates.push.services.mozilla.com/wpush/v2/abc',
]);

it('rejects endpoints that are not a recognised push service', function (string $endpoint): void {
    expect(passesSubscription(subscription($endpoint)))->toBeFalse();
})->with([
    'non-https' => 'http://fcm.googleapis.com/fcm/send/abc',
    'internal IP (SSRF)' => 'https://169.254.169.254/latest/meta-data',
    'loopback host' => 'https://localhost/push',
    'arbitrary host' => 'https://evil.example.com/steal',
    'lookalike googleapis host' => 'https://storage.googleapis.com/my-bucket',
    'not a url' => 'not-a-url',
]);

it('requires the p256dh and auth keys', function (): void {
    expect(passesSubscription(['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc']))->toBeFalse();
});
