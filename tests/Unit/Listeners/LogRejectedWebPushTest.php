<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushMessage;

function rejectedPush(?Response $response, string $reason): NotificationFailed
{
    $endpoint = 'https://web.push.apple.com/QSecretDeviceToken123';
    $subscription = new PushSubscription()->forceFill([
        'id' => 42,
        'subscribable_id' => 7,
        'endpoint' => $endpoint,
        'public_key' => 'secret-p256dh',
        'auth_token' => 'secret-auth',
    ]);

    return new NotificationFailed(
        new MessageSentReport(new Request('POST', $endpoint), $response, false, $reason),
        $subscription,
        new WebPushMessage(),
    );
}

it('logs one warning with the status, reason, push host, subscription and user', function (): void {
    Log::spy();

    event(rejectedPush(new Response(410, [], ''), 'Gone'));

    Log::shouldHaveReceived('warning')->once()->with('webpush.rejected', [
        'status' => 410,
        'reason' => 'Gone',
        'push_host' => 'web.push.apple.com',
        'subscription_id' => 42,
        'user_id' => 7,
    ]);
});

it('logs a transport failure with no status and strips the endpoint path from the reason', function (): void {
    Log::spy();

    event(rejectedPush(null, 'cURL error 28: Operation timed out for https://web.push.apple.com/QSecretDeviceToken123'));

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
        $logged = json_encode($context, JSON_THROW_ON_ERROR);

        return $message === 'webpush.rejected'
            && $context['status'] === null
            && str_contains((string) $context['reason'], 'cURL error 28')
            && str_contains((string) $context['reason'], 'web.push.apple.com')
            && ! str_contains($logged, 'QSecretDeviceToken123')
            && ! str_contains($logged, 'secret-p256dh')
            && ! str_contains($logged, 'secret-auth');
    });
});

it('truncates a long reason', function (): void {
    Log::spy();

    event(rejectedPush(new Response(400, [], ''), str_repeat('x', 1000)));

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => mb_strlen((string) $context['reason']) <= 203,
    );
});
