<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('fails when the bot token is not configured', function (): void {
    config(['services.telegram.bot_token' => null, 'services.telegram.webhook_secret' => 'secret']);

    $this->artisan('telegram:set-webhook')->assertExitCode(1);
});

it('fails when the webhook secret is not configured', function (): void {
    config(['services.telegram.bot_token' => 'token', 'services.telegram.webhook_secret' => '']);

    $this->artisan('telegram:set-webhook')->assertExitCode(1);
});

it('registers the webhook url and secret with Telegram', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.webhook_secret' => 'top-secret']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

    $this->artisan('telegram:set-webhook')->assertExitCode(0);

    Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), '/setWebhook')
        && $request['url'] === route('telegram.webhook.handle')
        && $request['secret_token'] === 'top-secret');
});

it('fails when Telegram rejects the webhook', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.webhook_secret' => 'top-secret']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad url'], 400)]);

    $this->artisan('telegram:set-webhook')->assertExitCode(1);
});
