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

it('fails when client credentials are missing', function (): void {
    config(['services.strava.client_id' => null]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'view'])
        ->expectsOutputToContain('not configured')
        ->assertFailed();
});

it('creates a subscription with the callback url and verify token', function (): void {
    Http::fake([
        'www.strava.com/api/v3/push_subscriptions' => Http::response(['id' => 555], 201),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'create'])
        ->expectsOutputToContain('Subscription created with id 555.')
        ->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://www.strava.com/api/v3/push_subscriptions'
        && $request['client_id'] === 'cid'
        && $request['client_secret'] === 'secret'
        && $request['verify_token'] === 'verify-tok'
        && $request['callback_url'] === route('strava.webhook.verify'));
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
        'www.strava.com/api/v3/push_subscriptions' => Http::response(['errors' => 'bad'], 400),
    ]);

    $this->artisan('strava:webhook-subscribe', ['--action' => 'create'])
        ->expectsOutputToContain('Strava rejected the subscription')
        ->assertFailed();
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
