<?php

declare(strict_types=1);

use App\Http\Controllers\Strava\StravaWebhookController;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.strava.client_id' => 'cid',
        'services.strava.client_secret' => 'secret',
        'services.strava.webhook_verify_token' => 'verify-tok',
        'services.strava.webhook_callback_token' => 'fake-callback-token',
    ]);
});

/**
 * Echo the verify-handshake challenge back so the command's pre-flight
 * self-verify passes. parse_str folds the dotted `hub.challenge` query key to
 * `hub_challenge` (PHP's dot-to-underscore rule), so read it from there.
 */
function fakeCallbackEchoes(): Closure
{
    return function ($request) {
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

        return Http::response(['hub.challenge' => $query['hub_challenge'] ?? '']);
    };
}

it('fails when client credentials are missing', function (): void {
    config(['services.strava.client_id' => null]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'view'])
        ->expectsOutputToContain('not configured')
        ->assertFailed();
});

it('creates a subscription with the callback url and verify token', function (): void {
    Http::fake([
        StravaWebhookController::callbackUrl().'*' => fakeCallbackEchoes(),
        'www.strava.com/api/v3/push_subscriptions' => Http::response(['id' => 555], 201),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'create'])
        ->expectsOutputToContain('Self-verify passed')
        ->expectsOutputToContain('Subscription created with id 555.')
        ->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://www.strava.com/api/v3/push_subscriptions'
        && $request['client_id'] === 'cid'
        && $request['client_secret'] === 'secret'
        && $request['verify_token'] === 'verify-tok'
        && $request['callback_url'] === url('/strava/webhook/fake-callback-token'));
});

it('aborts create without calling Strava when the self-verify handshake fails', function (): void {
    Http::fake([
        // Stale token / Cloudflare: the callback does not echo the challenge.
        StravaWebhookController::callbackUrl().'*' => Http::response(['error' => 'invalid verification request'], 403),
        'www.strava.com/api/v3/push_subscriptions' => Http::response(['id' => 555], 201),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'create'])
        ->expectsOutputToContain('Self-verify failed')
        ->expectsOutputToContain('Aborting before calling Strava')
        ->assertFailed();

    // The subscription POST must never fire when the handshake can't pass.
    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains((string) $request->url(), 'push_subscriptions'));
});

it('fails to create when the verify token is missing', function (): void {
    config(['services.strava.webhook_verify_token' => null]);
    Http::fake();

    $this->artisan('strava:webhook-subscribe', ['--action' => 'create'])
        ->expectsOutputToContain('STRAVA_WEBHOOK_VERIFY_TOKEN is not configured.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('surfaces a Strava error when create is rejected', function (): void {
    Http::fake([
        StravaWebhookController::callbackUrl().'*' => fakeCallbackEchoes(),
        'www.strava.com/api/v3/push_subscriptions' => Http::response(['errors' => 'bad'], 400),
    ]);

    expect(Artisan::call('strava:webhook-subscribe', ['--action' => 'create']))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('Strava rejected the subscription');
    expect($output)->not->toContain('Cloudflare');
});

it('hints at the edge when self-verify passes but Strava cannot GET a 200', function (): void {
    Http::fake([
        StravaWebhookController::callbackUrl().'*' => fakeCallbackEchoes(),
        'www.strava.com/api/v3/push_subscriptions' => Http::response([
            'message' => 'Bad Request',
            'errors' => [[
                'resource' => 'PushSubscription',
                'field' => 'callback url',
                'code' => 'GET to callback URL does not return 200',
            ]],
        ], 400),
    ]);

    // expectsOutputToContain binds each line to the first matching assertion;
    // assert one hint token that no earlier-asserted line also contains.
    $this->artisan('strava:webhook-subscribe', ['--action' => 'create'])
        ->expectsOutputToContain('Self-verify passed')
        ->expectsOutputToContain('Strava rejected the subscription')
        ->expectsOutputToContain('Cloudflare')
        ->assertFailed();
});

it('ensure skips creating when a matching subscription already exists', function (): void {
    Http::fake([
        'www.strava.com/api/v3/push_subscriptions*' => Http::response([
            ['id' => 555, 'callback_url' => StravaWebhookController::callbackUrl()],
        ]),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'ensure'])
        ->expectsOutputToContain('Already subscribed (id=555)')
        ->assertSuccessful();

    // No create POST when one already matches.
    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
});

it('ensure creates when no subscription exists', function (): void {
    Http::fake([
        StravaWebhookController::callbackUrl().'*' => fakeCallbackEchoes(),
        // GET (list) returns none; POST (create) returns the new id.
        'www.strava.com/api/v3/push_subscriptions*' => fn ($request) => $request->method() === 'POST'
            ? Http::response(['id' => 777], 201)
            : Http::response([], 200),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'ensure'])
        ->expectsOutputToContain('Subscription created with id 777.')
        ->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains((string) $request->url(), 'push_subscriptions'));
});

it('ensure refuses when a stale subscription with a different callback blocks the slot', function (string $staleCallback): void {
    Http::fake([
        'www.strava.com/api/v3/push_subscriptions*' => Http::response([
            ['id' => 999, 'callback_url' => $staleCallback],
        ]),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'ensure'])
        ->expectsOutputToContain('different callback')
        ->expectsOutputToContain('--action=delete --id=999')
        ->assertFailed();

    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
})->with([
    'another host' => 'https://old.example.test/strava/webhook',
    'untokenised callback' => fn (): string => url('/strava/webhook'),
    'rotated token' => fn (): string => url('/strava/webhook/previous-callback-token'),
]);

it('lists active subscriptions', function (): void {
    Http::fake([
        'www.strava.com/api/v3/push_subscriptions*' => Http::response([
            ['id' => 555, 'callback_url' => 'https://example.test/strava/webhook'],
        ]),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'view'])
        ->expectsOutputToContain('id=555')
        ->assertSuccessful();
});

it('never prints the raw callback or verify token', function (string $action, Closure $fake): void {
    Http::fake($fake());

    Artisan::call('strava:webhook-subscribe', ['--action' => $action]);

    expect(Artisan::output())
        ->toContain('/strava/webhook/****')
        ->not->toContain('fake-callback-token')
        ->not->toContain('verify-tok');
})->with([
    'create' => ['create', fn (): array => [
        StravaWebhookController::callbackUrl().'*' => fakeCallbackEchoes(),
        'www.strava.com/api/v3/push_subscriptions' => Http::response(['id' => 555], 201),
    ]],
    'create with an unreachable callback' => ['create', fn (): array => [
        StravaWebhookController::callbackUrl().'*' => fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out for '.StravaWebhookController::callbackUrl().'?hub.mode=subscribe&hub.verify_token=verify-tok',
        ),
    ]],
    'ensure already subscribed' => ['ensure', fn (): array => [
        'www.strava.com/api/v3/push_subscriptions*' => Http::response([
            ['id' => 555, 'callback_url' => StravaWebhookController::callbackUrl()],
        ]),
    ]],
    'ensure blocked by another token' => ['ensure', fn (): array => [
        'www.strava.com/api/v3/push_subscriptions*' => Http::response([
            ['id' => 555, 'callback_url' => url('/strava/webhook/fake-callback-token-old')],
        ]),
    ]],
    'view' => ['view', fn (): array => [
        'www.strava.com/api/v3/push_subscriptions*' => Http::response([
            ['id' => 555, 'callback_url' => StravaWebhookController::callbackUrl()],
        ]),
    ]],
]);

it('warns when there is no active subscription', function (): void {
    Http::fake([
        'www.strava.com/api/v3/push_subscriptions*' => Http::response([]),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'view'])
        ->expectsOutputToContain('No active push subscription.')
        ->assertSuccessful();
});

it('deletes a subscription by id, sending credentials in the query string', function (): void {
    Http::fake([
        'www.strava.com/api/v3/push_subscriptions/555*' => Http::response('', 204),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'delete', '--id' => '555'])
        ->expectsOutputToContain('Subscription 555 deleted.')
        ->assertSuccessful();

    // Strava reads client creds from the query string on DELETE; sending them
    // in the form body leaves the client unidentified and Strava 404s.
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_starts_with((string) $request->url(), 'https://www.strava.com/api/v3/push_subscriptions/555?')
        && str_contains((string) $request->url(), 'client_id=cid')
        && str_contains((string) $request->url(), 'client_secret=secret'));
});

it('fails to delete without an id', function (): void {
    config(['services.strava.webhook_subscription_id' => null]);
    Http::fake();

    $this->artisan('strava:webhook-subscribe', ['--action' => 'delete'])
        ->expectsOutputToContain('Pass --id=')
        ->assertFailed();

    Http::assertNothingSent();
});

it('rejects an unknown action', function (): void {
    Http::fake();

    $this->artisan('strava:webhook-subscribe', ['--action' => 'frobnicate'])
        ->expectsOutputToContain('Unknown --action.')
        ->assertFailed();
});
