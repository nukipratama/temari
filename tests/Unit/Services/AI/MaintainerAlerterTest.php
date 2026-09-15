<?php

declare(strict_types=1);

use App\Jobs\AI\FlushDeadLetterAlertJob;
use App\Models\NotificationPreference;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\AI\MaintainerAlerter;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
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

    $client->shouldReceive('sendMessage')->once()->with(1001, Mockery::pattern('/gave up/'));
    $client->shouldReceive('sendMessage')->once()->with(1002, Mockery::pattern('/gave up/'));

    app(MaintainerAlerter::class)->deadLettered();
});

it('is a no-op when Telegram is unconfigured', function (): void {
    Config::set('services.telegram.bot_token', '');
    $client = fakeTelegram();
    adminWithChat(1001);

    $client->shouldNotReceive('sendMessage');

    app(MaintainerAlerter::class)->deadLettered();
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

    app(MaintainerAlerter::class)->deadLettered();
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

    $client->shouldReceive('sendMessage')->once()->with(3002, Mockery::pattern('/narrating again/'));

    app(MaintainerAlerter::class)->syncPauseState(null);

    expect(app(AppConfig::class)->get(AppConfigKey::AiLastPauseReason))->toBeNull();
});

it('pushes a scheduler-failure alert', function (): void {
    $client = fakeTelegram();
    adminWithChat(4001);

    $client->shouldReceive('sendMessage')->once()->with(4001, Mockery::pattern('/ai:self-heal/'));

    app(MaintainerAlerter::class)->schedulerFailed('ai:self-heal');
});

it('pushes a deploy-failure alert', function (): void {
    $client = fakeTelegram();
    adminWithChat(6001);

    $client->shouldReceive('sendMessage')->once()->with(6001, Mockery::pattern('/Prod deploy failed/'));

    app(MaintainerAlerter::class)->deployFailed('healthcheck failed');
});

it('swallows a send failure so an alert never fails its caller', function (): void {
    $client = fakeTelegram();
    adminWithChat(5001);

    $client->shouldReceive('sendMessage')->andThrow(new TelegramApiException('blocked', 403));

    expect(fn () => app(MaintainerAlerter::class)->deadLettered())->not->toThrow(Throwable::class);
});

/**
 * Maintainer alerts deliberately bypass the per-channel mute added in #406.
 *
 * They are operational, not product: telling a solo operator that the AI
 * pipeline has stalled. MaintainerAlerter is also Telegram-only, so honouring
 * the mute would not reroute these alerts, it would delete them — and the
 * failure they exist to catch is exactly the one you notice days late.
 *
 * The Settings copy states this scope out loud -- bot replies and system alerts
 * still arrive -- so the carve-out is documented to the user rather than being a
 * surprise. Pinned here so it stays a decision, not an accident.
 */
it('still alerts an admin who has muted the Telegram channel', function (): void {
    $admin = adminWithChat(4321);
    NotificationPreference::factory()->for($admin)->create(['telegram_enabled' => false]);

    $client = fakeTelegram();
    $client->shouldReceive('sendMessage')->once()->with(4321, Mockery::type('string'));

    app(MaintainerAlerter::class)->deadLettered();
});

it('still respects an unconfigured bot token, mute or not', function (): void {
    Config::set('services.telegram.bot_token', '');
    $admin = adminWithChat(4321);
    NotificationPreference::factory()->for($admin)->create(['telegram_enabled' => false]);

    $client = fakeTelegram();
    $client->shouldNotReceive('sendMessage');

    app(MaintainerAlerter::class)->deadLettered();
});

it('coalesces a burst of dead-letters into exactly one delayed flush job', function (): void {
    Bus::fake();

    $alerter = app(MaintainerAlerter::class);
    $alerter->deadLettered();
    $alerter->deadLettered();
    $alerter->deadLettered();

    Bus::assertDispatchedTimes(FlushDeadLetterAlertJob::class, 1);
    Bus::assertDispatched(FlushDeadLetterAlertJob::class, fn (FlushDeadLetterAlertJob $job): bool => $job->delay === 90);
});

it('flushDeadLetterWindow sends one summary message carrying the coalesced count', function (): void {
    Bus::fake();
    $client = fakeTelegram();
    adminWithChat(7001);

    $alerter = app(MaintainerAlerter::class);
    $alerter->deadLettered();
    $alerter->deadLettered();
    $alerter->deadLettered();

    $client->shouldReceive('sendMessage')->once()->with(7001, Mockery::pattern('/^3 AI blocks gave up/'));

    $alerter->flushDeadLetterWindow();
});

it('flushDeadLetterWindow is a no-op when nothing is pending in the window', function (): void {
    $client = fakeTelegram();
    adminWithChat(7002);

    $client->shouldNotReceive('sendMessage');

    app(MaintainerAlerter::class)->flushDeadLetterWindow();
});

it('a dead-letter after a flush schedules its own new flush instead of being lost', function (): void {
    Bus::fake();
    $alerter = app(MaintainerAlerter::class);

    $alerter->deadLettered();
    $alerter->flushDeadLetterWindow();

    $alerter->deadLettered();

    Bus::assertDispatchedTimes(FlushDeadLetterAlertJob::class, 2);
});

it('totalCeilingReached names the spend, the ceiling and how many athletes degraded', function (): void {
    $client = fakeTelegram();
    adminWithChat(7003);

    $client->shouldReceive('sendMessage')->once()->with(
        7003,
        'App-wide AI spend passed the daily ceiling: $6.20 of $5.00. 4 athletes are now served rule-based until midnight.',
    );

    app(MaintainerAlerter::class)->totalCeilingReached(6.2, 5.0, 4);
});

it('totalCeilingReached singularises a lone degraded athlete', function (): void {
    $client = fakeTelegram();
    adminWithChat(7004);

    $client->shouldReceive('sendMessage')->once()->with(7004, Mockery::pattern('/1 athlete is now served rule-based/'));

    app(MaintainerAlerter::class)->totalCeilingReached(6.0, 5.0, 1);
});

it('totalCeilingReached pushes once per cooldown, not once per gated dispatch', function (): void {
    $client = fakeTelegram();
    adminWithChat(7005);

    $client->shouldReceive('sendMessage')->once();

    $alerter = app(MaintainerAlerter::class);
    $alerter->totalCeilingReached(6.0, 5.0, 2);
    $alerter->totalCeilingReached(7.5, 5.0, 2);
});

it('userCeilingReached names the athlete, the spend and the slice', function (): void {
    $client = fakeTelegram();
    adminWithChat(7101);

    $client->shouldReceive('sendMessage')->once()->with(
        7101,
        'Athlete 42 passed their daily AI slice: $1.20 of $1.00. Their narration is served rule-based until midnight.',
    );

    app(MaintainerAlerter::class)->userCeilingReached(42, 1.2, 1.0);
});

it('userCeilingReached pushes once per athlete per day, not once per gated dispatch', function (): void {
    $client = fakeTelegram();
    adminWithChat(7102);

    $client->shouldReceive('sendMessage')->once()->with(7102, Mockery::pattern('/Athlete 42/'));
    $client->shouldReceive('sendMessage')->once()->with(7102, Mockery::pattern('/Athlete 43/'));

    $alerter = app(MaintainerAlerter::class);
    $alerter->userCeilingReached(42, 1.2, 1.0);
    $alerter->userCeilingReached(42, 1.4, 1.0);
    $alerter->userCeilingReached(43, 1.1, 1.0);
});

it('totalCeilingApproaching warns once the app-wide ceiling is 80 per cent spent', function (): void {
    $client = fakeTelegram();
    adminWithChat(7201);

    $client->shouldReceive('sendMessage')->once()->with(
        7201,
        'App-wide AI spend is at $4.00 of the $5.00 daily ceiling (80%). Past it every athlete is served rule-based.',
    );

    app(MaintainerAlerter::class)->totalCeilingApproaching(4.0, 5.0);
});

it('totalCeilingApproaching stays quiet below the warning threshold', function (): void {
    $client = fakeTelegram();
    adminWithChat(7202);

    $client->shouldNotReceive('sendMessage');

    app(MaintainerAlerter::class)->totalCeilingApproaching(3.9, 5.0);
});

it('totalCeilingApproaching pushes once per cooldown', function (): void {
    $client = fakeTelegram();
    adminWithChat(7203);

    $client->shouldReceive('sendMessage')->once();

    $alerter = app(MaintainerAlerter::class);
    $alerter->totalCeilingApproaching(4.1, 5.0);
    $alerter->totalCeilingApproaching(4.6, 5.0);
});

it('stravaBudgetLow warns when under a tenth of the shared 15-minute budget is left', function (): void {
    $client = fakeTelegram();
    adminWithChat(7301);

    $client->shouldReceive('sendMessage')->once()->with(
        7301,
        'Strava reads are nearly spent: 12 of 200 left in this 15-minute window. Background hydration backs off first.',
    );

    app(MaintainerAlerter::class)->stravaBudgetLow(12, 200);
});

it('stravaBudgetLow stays quiet while the budget is healthy', function (): void {
    $client = fakeTelegram();
    adminWithChat(7302);

    $client->shouldNotReceive('sendMessage');

    app(MaintainerAlerter::class)->stravaBudgetLow(20, 200);
});

// The Strava limit is per client app, so the dedupe key is global: every
// athlete's sync shares one window, and one warning covers all of them.
it('stravaBudgetLow pushes once per 15-minute window, then again in the next one', function (): void {
    $client = fakeTelegram();
    adminWithChat(7303);

    $client->shouldReceive('sendMessage')->twice();

    Carbon::setTestNow('2026-09-15 10:01:00');
    $alerter = app(MaintainerAlerter::class);
    $alerter->stravaBudgetLow(5, 200);
    $alerter->stravaBudgetLow(4, 200);

    Carbon::setTestNow('2026-09-15 10:16:00');
    $alerter->stravaBudgetLow(3, 200);

    Carbon::setTestNow();
});

it('spendDigest reports the app-wide total, its headroom and a line per athlete', function (): void {
    $client = fakeTelegram();
    adminWithChat(7401);

    $client->shouldReceive('sendMessage')->once()->with(
        7401,
        "AI spend today: \$0.42 of \$5.00 (\$4.58 left). 12 calls, 30,400 tokens.\n"
        ."- athlete 7: 8 calls, 20,000 tokens, \$0.30 (\$0.70 left)\n"
        .'- athlete 9: 4 calls, 10,400 tokens, $0.12 ($0.88 left)',
    );

    app(MaintainerAlerter::class)->spendDigest([
        ['userId' => 7, 'calls' => 8, 'tokens' => 20_000, 'cost' => 0.30],
        ['userId' => 9, 'calls' => 4, 'tokens' => 10_400, 'cost' => 0.12],
    ], 0.42, 1.0, 5.0);
});

it('spendDigest says so plainly on a day nobody spent anything', function (): void {
    $client = fakeTelegram();
    adminWithChat(7402);

    $client->shouldReceive('sendMessage')->once()->with(7402, Mockery::pattern('/No athlete spent anything today/'));

    app(MaintainerAlerter::class)->spendDigest([], 0.0, 1.0, 5.0);
});
