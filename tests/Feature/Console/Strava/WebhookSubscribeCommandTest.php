<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.strava.client_id' => 'cid',
        'services.strava.client_secret' => 'secret',
        'services.strava.webhook_verify_token' => 'verify-tok',
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
        route('strava.webhook.verify').'*' => fakeCallbackEchoes(),
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
        && $request['callback_url'] === route('strava.webhook.verify'));
});

it('aborts create without calling Strava when the self-verify handshake fails', function (): void {
    Http::fake([
        // Stale token / Cloudflare: the callback does not echo the challenge.
        route('strava.webhook.verify').'*' => Http::response(['error' => 'invalid verification request'], 403),
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
        route('strava.webhook.verify').'*' => fakeCallbackEchoes(),
        'www.strava.com/api/v3/push_subscriptions' => Http::response(['errors' => 'bad'], 400),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'create'])
        ->expectsOutputToContain('Strava rejected the subscription')
        ->assertFailed();
});

it('ensure skips creating when a matching subscription already exists', function (): void {
    Http::fake([
        'www.strava.com/api/v3/push_subscriptions*' => Http::response([
            ['id' => 555, 'callback_url' => route('strava.webhook.verify')],
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
        route('strava.webhook.verify').'*' => fakeCallbackEchoes(),
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

it('ensure refuses when a stale subscription with a different callback blocks the slot', function (): void {
    Http::fake([
        'www.strava.com/api/v3/push_subscriptions*' => Http::response([
            ['id' => 999, 'callback_url' => 'https://old.example.test/strava/webhook'],
        ]),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'ensure'])
        ->expectsOutputToContain('different callback')
        ->expectsOutputToContain('--action=delete --id=999')
        ->assertFailed();

    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
});

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

it('warns when there is no active subscription', function (): void {
    Http::fake([
        'www.strava.com/api/v3/push_subscriptions*' => Http::response([]),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'view'])
        ->expectsOutputToContain('No active push subscription.')
        ->assertSuccessful();
});

it('deletes a subscription by id', function (): void {
    Http::fake([
        'www.strava.com/api/v3/push_subscriptions/555' => Http::response('', 204),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'delete', '--id' => '555'])
        ->expectsOutputToContain('Subscription 555 deleted.')
        ->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://www.strava.com/api/v3/push_subscriptions/555'
        && $request['client_id'] === 'cid');
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
