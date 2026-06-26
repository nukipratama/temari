<?php

declare(strict_types=1);

use App\Jobs\Telegram\HandleTelegramUpdateJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
});

it('refuses to run in production', function (): void {
    $this->app['env'] = 'production';

    $this->artisan('telegram:listen')->assertExitCode(1);

    $this->app['env'] = 'testing';
});

it('fails when the bot token is not configured', function (): void {
    config(['services.telegram.bot_token' => null]);

    $this->artisan('telegram:listen')->assertExitCode(1);
});

it('processes each update and advances the offset across batches', function (): void {
    Bus::fake();
    Http::fakeSequence('api.telegram.org/*')
        ->push(['ok' => true, 'result' => [
            ['update_id' => 10, 'message' => ['chat' => ['id' => 1], 'text' => '/start a']],
            ['update_id' => 11, 'message' => ['chat' => ['id' => 2], 'text' => '/stop']],
        ]])
        ->push(['ok' => true, 'result' => []]);

    $this->artisan('telegram:listen', ['--max-batches' => 2])->assertExitCode(0);

    Bus::assertDispatchedTimes(HandleTelegramUpdateJob::class, 2);

    // First poll starts at offset 0; the second acks past the highest update_id.
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/getUpdates') && $request['offset'] === 0);
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/getUpdates') && $request['offset'] === 12);
});
