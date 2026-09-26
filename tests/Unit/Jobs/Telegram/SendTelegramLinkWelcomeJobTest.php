<?php

declare(strict_types=1);

use App\Jobs\Telegram\SendTelegramLinkWelcomeJob;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
});

it('sends one account-naming welcome to the linked chat', function (): void {
    new SendTelegramLinkWelcomeJob(555, 'Budi Santoso')->handle(app(TelegramClient::class));

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['chat_id'] === 555
        && str_contains((string) $request['text'], 'Budi Santoso'));
});
