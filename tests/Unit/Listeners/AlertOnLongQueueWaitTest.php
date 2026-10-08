<?php

declare(strict_types=1);

use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Horizon\Events\LongWaitDetected;

uses(RefreshDatabase::class);

it('pages once per queue while its wait stays long, and again for another queue', function (): void {
    Config::set('services.telegram.bot_token', 'test-bot-token');
    $client = Mockery::mock(TelegramClient::class);
    app()->instance(TelegramClient::class, $client);
    $admin = User::factory()->admin()->create();
    TelegramConnection::factory()->for($admin)->create(['chat_id' => 4201]);

    $client->shouldReceive('sendMessage')->once()->with(4201, 'Horizon queue `redis:ai` is backed up: its wait is about 480 s. Check Horizon and the logs.', 5);
    $client->shouldReceive('sendMessage')->once()->with(4201, 'Horizon queue `redis:default` is backed up: its wait is about 75 s. Check Horizon and the logs.', 5);

    event(new LongWaitDetected('redis', 'ai', 480));
    event(new LongWaitDetected('redis', 'ai', 540));
    event(new LongWaitDetected('redis', 'default', 75));
    event(new LongWaitDetected('redis', 'default', 80));
});
