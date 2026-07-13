<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaintainerAlerter;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Configured Telegram so broadcast actually attempts a send.
    Config::set('services.telegram.bot_token', 'test-bot-token');
});

/** Bind a mock TelegramClient and return it for assertions. */
function fakeTelegram(): TelegramClient
{
    $client = Mockery::mock(TelegramClient::class);
    app()->instance(TelegramClient::class, $client);

    return $client;
}

/** An admin with an active Telegram connection at $chatId. */
function adminWithChat(int $chatId): User
{
    $admin = User::factory()->admin()->create();
    TelegramConnection::factory()->for($admin)->create(['chat_id' => $chatId]);

    return $admin;
}

it('pushes a dead-letter alert to every admin chat', function (): void {
    $client = fakeTelegram();
    adminWithChat(1001);
    adminWithChat(1002);

    $client->shouldReceive('sendMessage')->once()->with(1001, Mockery::pattern('/nyerah/'));
    $client->shouldReceive('sendMessage')->once()->with(1002, Mockery::pattern('/nyerah/'));

    $row = Analysis::factory()->failed()->make(['analysis_type' => AnalysisType::WeeklyRecap]);

    app(MaintainerAlerter::class)->deadLettered($row);
});

it('is a no-op when Telegram is unconfigured', function (): void {
    Config::set('services.telegram.bot_token', '');
    $client = fakeTelegram();
    adminWithChat(1001);

    $client->shouldNotReceive('sendMessage');

    $row = Analysis::factory()->failed()->make(['analysis_type' => AnalysisType::WeeklyRecap]);
    app(MaintainerAlerter::class)->deadLettered($row);
});

it('skips non-admins and revoked connections', function (): void {
    $client = fakeTelegram();

    // Non-admin with a chat — must not be alerted.
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 2001]);

    // Admin whose connection is revoked — must not be alerted.
    $revokedAdmin = User::factory()->admin()->create();
    TelegramConnection::factory()->for($revokedAdmin)->create(['chat_id' => 2002, 'revoked_at' => now()]);

    // The only valid target.
    adminWithChat(2003);

    $client->shouldReceive('sendMessage')->once()->with(2003, Mockery::any());

    $row = Analysis::factory()->failed()->make(['analysis_type' => AnalysisType::WeeklyRecap]);
    app(MaintainerAlerter::class)->deadLettered($row);
});

it('alerts on a pause transition and stores the reason, then stays quiet when unchanged', function (): void {
    $client = fakeTelegram();
    adminWithChat(3001);

    $client->shouldReceive('sendMessage')->once()->with(3001, Mockery::pattern('/config/'));

    $alerter = app(MaintainerAlerter::class);
    $alerter->syncPauseState('config');
    // Same reason again: no second push.
    $alerter->syncPauseState('config');

    expect(app(AppConfig::class)->get(AppConfigKey::AiLastPauseReason))->toBe('config');
});

it('alerts a resume when the reason clears back to null', function (): void {
    $client = fakeTelegram();
    adminWithChat(3002);

    app(AppConfig::class)->set(AppConfigKey::AiLastPauseReason, 'cost_ceiling');

    $client->shouldReceive('sendMessage')->once()->with(3002, Mockery::pattern('/bisa narasi lagi/'));

    app(MaintainerAlerter::class)->syncPauseState(null);

    expect(app(AppConfig::class)->get(AppConfigKey::AiLastPauseReason))->toBeNull();
});

it('pushes a scheduler-failure alert', function (): void {
    $client = fakeTelegram();
    adminWithChat(4001);

    $client->shouldReceive('sendMessage')->once()->with(4001, Mockery::pattern('/ai:self-heal/'));

    app(MaintainerAlerter::class)->schedulerFailed('ai:self-heal');
});

it('swallows a send failure so an alert never fails its caller', function (): void {
    $client = fakeTelegram();
    adminWithChat(5001);

    $client->shouldReceive('sendMessage')->andThrow(new TelegramApiException('blocked', 403));

    $row = Analysis::factory()->failed()->make(['analysis_type' => AnalysisType::WeeklyRecap]);

    expect(fn () => app(MaintainerAlerter::class)->deadLettered($row))->not->toThrow(Throwable::class);
});
