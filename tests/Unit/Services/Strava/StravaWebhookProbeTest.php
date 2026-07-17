<?php

declare(strict_types=1);

use App\Services\Strava\StravaWebhookProbe;
use Illuminate\Support\Facades\Http;

it('passes when the callback echoes the challenge', function (): void {
    Http::fake([
        'example.test/strava/webhook*' => function ($request) {
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

            return Http::response(['hub.challenge' => $query['hub_challenge'] ?? '']);
        },
    ]);

    $result = new StravaWebhookProbe()->probe('https://example.test/strava/webhook', 'verify-tok');

    expect($result['passed'])->toBeTrue()
        ->and($result['status'])->toBe(200);
});

it('fails when the callback does not return a 200 challenge', function (): void {
    Http::fake([
        'example.test/strava/webhook*' => Http::response(['error' => 'nope'], 403),
    ]);

    $result = new StravaWebhookProbe()->probe('https://example.test/strava/webhook', 'verify-tok');

    expect($result['passed'])->toBeFalse()
        ->and($result['status'])->toBe(403);
});

it('fails closed when the callback echoes the wrong challenge', function (): void {
    Http::fake([
        'example.test/strava/webhook*' => Http::response(['hub.challenge' => 'not-the-one']),
    ]);

    $result = new StravaWebhookProbe()->probe('https://example.test/strava/webhook', 'verify-tok');

    expect($result['passed'])->toBeFalse();
});

it('fails closed when the request throws', function (): void {
    Http::fake(fn () => throw new RuntimeException('connection refused'));

    $result = new StravaWebhookProbe()->probe('https://example.test/strava/webhook', 'verify-tok');

    expect($result['passed'])->toBeFalse()
        ->and($result['status'])->toBe(0)
        ->and($result['detail'])->toContain('connection refused');
});
