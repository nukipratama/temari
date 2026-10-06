<?php

declare(strict_types=1);

use App\Jobs\AI\FlushDeadLetterAlertJob;
use App\Jobs\AI\SendMaintainerAlertJob;
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
use Illuminate\Support\Facades\Cache;
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

it('stamps when a pause begins and keeps that stamp while only the reason changes', function (): void {
    Carbon::setTestNow('2026-06-17 08:00:00');
    $alerter = app(MaintainerAlerter::class);

    expect($alerter->syncPauseState('kill_switch'))->toBeNull();

    Carbon::setTestNow('2026-06-17 09:00:00');
    expect($alerter->syncPauseState('config'))->toBeNull()
        ->and(app(AppConfig::class)->get(AppConfigKey::AiPauseStartedAt))
        ->toBe(Carbon::parse('2026-06-17 08:00:00')->toIso8601String());

    Carbon::setTestNow();
});

it('returns the pause start once when a non-ceiling pause lifts, then nothing on the next sweep', function (): void {
    Carbon::setTestNow('2026-06-17 08:00:00');
    $alerter = app(MaintainerAlerter::class);
    $alerter->syncPauseState('config');

    Carbon::setTestNow('2026-06-17 10:00:00');
    $lifted = $alerter->syncPauseState(null);

    expect($lifted?->equalTo(Carbon::parse('2026-06-17 08:00:00')))->toBeTrue()
        ->and($alerter->syncPauseState(null))->toBeNull()
        ->and(app(AppConfig::class)->get(AppConfigKey::AiPauseStartedAt))->toBeNull();

    Carbon::setTestNow();
});

it('does not report a resume when the app-wide cost ceiling lifts', function (): void {
    $alerter = app(MaintainerAlerter::class);
    $alerter->syncPauseState('cost_ceiling');

    expect($alerter->syncPauseState(null))->toBeNull()
        ->and(app(AppConfig::class)->get(AppConfigKey::AiPauseStartedAt))->toBeNull();
});

it('pushes a scheduler-failure alert inline with a 5 second timeout', function (): void {
    Bus::fake();
    $client = fakeTelegram();
    adminWithChat(4001);

    $client->shouldReceive('sendMessage')->once()->with(4001, Mockery::pattern('/Scheduler failed to run `ai:self-heal`/'), 5);

    app(MaintainerAlerter::class)->schedulerFailed('ai:self-heal');

    Bus::assertNotDispatched(SendMaintainerAlertJob::class);
});

it('pages a failing entry once per incident, and again once a day while it keeps failing', function (): void {
    $client = fakeTelegram();
    adminWithChat(4002);
    Carbon::setTestNow('2026-10-06 10:00:00');

    $client->shouldReceive('sendMessage')->twice()->with(4002, Mockery::pattern('/failed to run `strava:sync`/'), 5);

    $alerter = app(MaintainerAlerter::class);
    $alerter->schedulerFailed('strava:sync');
    Carbon::setTestNow('2026-10-06 11:00:00');
    $alerter->schedulerFailed('strava:sync');
    Carbon::setTestNow('2026-10-07 09:59:59');
    $alerter->schedulerFailed('strava:sync');
    Carbon::setTestNow('2026-10-07 10:00:00');
    $alerter->schedulerFailed('strava:sync');

    Carbon::setTestNow();
});

it('keeps each entry its own incident', function (): void {
    $client = fakeTelegram();
    adminWithChat(4003);

    $client->shouldReceive('sendMessage')->once()->with(4003, Mockery::pattern('/`ai:self-heal`/'), 5);
    $client->shouldReceive('sendMessage')->once()->with(4003, Mockery::pattern('/`ai:catch-up`/'), 5);

    $alerter = app(MaintainerAlerter::class);
    $alerter->schedulerFailed('ai:self-heal');
    $alerter->schedulerFailed('ai:catch-up');
});

it('sends one recovered line when a failing entry next succeeds, then pages afresh on a new failure', function (): void {
    $client = fakeTelegram();
    adminWithChat(4004);

    $client->shouldReceive('sendMessage')->twice()->with(4004, Mockery::pattern('/failed to run `ai:self-heal`/'), 5);
    $client->shouldReceive('sendMessage')->once()->with(4004, 'Scheduler `ai:self-heal` recovered: its latest run succeeded.', 5);

    $alerter = app(MaintainerAlerter::class);
    $alerter->schedulerFailed('ai:self-heal');
    $alerter->schedulerRecovered('ai:self-heal');
    $alerter->schedulerRecovered('ai:self-heal');
    $alerter->schedulerFailed('ai:self-heal');
});

it('stays silent on a success outside any incident', function (): void {
    $client = fakeTelegram();
    adminWithChat(4005);

    $client->shouldNotReceive('sendMessage');

    app(MaintainerAlerter::class)->schedulerRecovered('ai:self-heal');
});

it('pages anyway when the incident key cannot be read, but sends no recovery line', function (): void {
    $client = fakeTelegram();
    adminWithChat(4006);
    Cache::shouldReceive('store')->with('durable')->andThrow(new RuntimeException('redis down'));

    $client->shouldReceive('sendMessage')->once()->with(4006, Mockery::pattern('/failed to run/'), 5);
    $client->shouldNotReceive('sendMessage')->with(4006, Mockery::pattern('/recovered|back on time/'), 5);

    $alerter = app(MaintainerAlerter::class);
    $alerter->schedulerFailed('ai:self-heal');
    $alerter->schedulerRecovered('ai:self-heal');
    $alerter->schedulerOnTime('ai:self-heal');
    $alerter->athletesRecovered('strava:sync');
});

it('sends a cooldown alert anyway when the cooldown key cannot be claimed', function (): void {
    Bus::fake();
    adminWithChat(4007);
    Cache::shouldReceive('add')->andThrow(new RuntimeException('redis down'));

    app(MaintainerAlerter::class)->telegramBotRejected(401);

    Bus::assertDispatched(SendMaintainerAlertJob::class);
});

it('pages skipped athletes once per incident per command, again a day later, and recovers on a clean run', function (): void {
    $client = fakeTelegram();
    adminWithChat(4009);
    Carbon::setTestNow('2026-10-06 10:00:00');

    $client->shouldReceive('sendMessage')->twice()->with(4009, Mockery::pattern('/`strava:sync` skipped/'), 5);
    $client->shouldReceive('sendMessage')->once()->with(4009, Mockery::pattern('/`strava:sync-zones` skipped/'), 5);
    $client->shouldReceive('sendMessage')->once()->with(4009, 'Scheduler `strava:sync` recovered: no athlete failed on its latest run.', 5);

    $alerter = app(MaintainerAlerter::class);
    $alerter->athletesRecovered('strava:sync');
    $alerter->athletesFailed('strava:sync', 2);
    $alerter->athletesFailed('strava:sync-zones', 1);
    Carbon::setTestNow('2026-10-06 11:00:00');
    $alerter->athletesFailed('strava:sync', 1);
    Carbon::setTestNow('2026-10-07 10:00:00');
    $alerter->athletesFailed('strava:sync', 1);
    $alerter->athletesRecovered('strava:sync');
    $alerter->athletesRecovered('strava:sync');

    Carbon::setTestNow();
});

it('pages a late entry once, inline, and sends one line when it is back on time', function (): void {
    Bus::fake();
    $client = fakeTelegram();
    adminWithChat(4010);

    $client->shouldReceive('sendMessage')->once()->with(4010, 'Scheduler `strava:sync` is late: it has missed its schedule. Last run Oct 6 09:00. Check the scheduler container and the logs.', 5);
    $client->shouldReceive('sendMessage')->once()->with(4010, 'Scheduler `strava:sync` is back on time.', 5);

    $alerter = app(MaintainerAlerter::class);
    $alerter->schedulerOnTime('strava:sync');
    $alerter->schedulerLate('strava:sync', Carbon::parse('2026-10-06 09:00:00'));
    $alerter->schedulerLate('strava:sync', Carbon::parse('2026-10-06 09:00:00'));
    $alerter->schedulerOnTime('strava:sync');
    $alerter->schedulerOnTime('strava:sync');

    Bus::assertNotDispatched(SendMaintainerAlertJob::class);
});

it('names no last run for an entry that has never run', function (): void {
    $client = fakeTelegram();
    adminWithChat(4011);

    $client->shouldReceive('sendMessage')->once()->with(4011, 'Scheduler `plan:regenerate` is late: it has missed its schedule. Check the scheduler container and the logs.', 5);

    app(MaintainerAlerter::class)->schedulerLate('plan:regenerate', null);
});

it('pushes the skipped-athletes alert inline', function (): void {
    Bus::fake();
    $client = fakeTelegram();
    adminWithChat(4008);

    $client->shouldReceive('sendMessage')->once()->with(4008, 'Scheduler `ai:daily-briefing` skipped 2 athletes after errors. Check the logs.', 5);

    app(MaintainerAlerter::class)->athletesFailed('ai:daily-briefing', 2);

    Bus::assertNotDispatched(SendMaintainerAlertJob::class);
});

it('pushes one overdue-Monday alert per week, naming each entry still behind', function (): void {
    $client = fakeTelegram();
    adminWithChat(4101);
    Carbon::setTestNow('2026-09-14 06:00:00');

    $client->shouldReceive('sendMessage')->once()->with(4101, Mockery::on(
        fn (string $message): bool => str_contains($message, 'streak:settle (2 athletes unsettled)')
            && str_contains($message, 'plan:regenerate'),
    ));

    $alerter = app(MaintainerAlerter::class);
    $alerter->mondayEntriesOverdue(['streak:settle (2 athletes unsettled)', 'plan:regenerate']);
    $alerter->mondayEntriesOverdue(['plan:regenerate']);

    Carbon::setTestNow();
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
    Bus::assertDispatched(fn (FlushDeadLetterAlertJob $job): bool => $job->delay === 90);
});

it('flushDeadLetterWindow queues one summary message carrying the coalesced count', function (): void {
    Bus::fake();

    $alerter = app(MaintainerAlerter::class);
    $alerter->deadLettered();
    $alerter->deadLettered();
    $alerter->deadLettered();

    $alerter->flushDeadLetterWindow();

    Bus::assertDispatched(fn (SendMaintainerAlertJob $job): bool => str_starts_with($job->message, '3 AI blocks gave up'));
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
        ."- athlete 9: 4 calls, 10,400 tokens, \$0.12 (\$0.88 left)\n"
        .'AI spend yesterday, final: $0.58.',
    );

    app(MaintainerAlerter::class)->spendDigest([
        ['userId' => 7, 'calls' => 8, 'tokens' => 20_000, 'cost' => 0.30],
        ['userId' => 9, 'calls' => 4, 'tokens' => 10_400, 'cost' => 0.12],
    ], 0.42, 1.0, 5.0, 0.58);
});

it('spendDigest says so plainly on a day nobody spent anything', function (): void {
    $client = fakeTelegram();
    adminWithChat(7402);

    $client->shouldReceive('sendMessage')->once()->with(7402, Mockery::pattern('/No athlete spent anything today/'));

    app(MaintainerAlerter::class)->spendDigest([], 0.0, 1.0, 5.0, 0.0);
});

it('exceptionDigest lists each new fingerprint with its first-seen time and count', function (): void {
    $client = fakeTelegram();
    adminWithChat(7403);

    $client->shouldReceive('sendMessage')->once()->with(
        7403,
        "2 new exceptions since the last digest.\n"
        ."- RuntimeException at app/Services/Foo.php:12, first seen Sep 30 10:00, 3 times\n"
        .'- browser error at /build/assets/app.js:1:2 (#abcd1234), first seen Sep 30 11:15, once',
    );

    app(MaintainerAlerter::class)->exceptionDigest([
        ['label' => 'RuntimeException at app/Services/Foo.php:12', 'first_seen' => '2026-09-30T10:00:00+07:00', 'count' => 3],
        ['label' => 'browser error at /build/assets/app.js:1:2 (#abcd1234)', 'first_seen' => '2026-09-30T11:15:00+07:00', 'count' => 1],
    ]);
});

it('exceptionDigest folds a long list into a count so the message stays sendable', function (): void {
    $client = fakeTelegram();
    adminWithChat(7404);

    $entries = array_map(fn (int $i): array => [
        'label' => "RuntimeException at app/F{$i}.php:1",
        'first_seen' => '2026-09-30T10:00:00+07:00',
        'count' => 1,
    ], range(1, MaintainerAlerter::EXCEPTION_DIGEST_MAX_LINES + 4));

    $client->shouldReceive('sendMessage')->once()->with(7404, Mockery::on(
        fn (string $message): bool => str_starts_with($message, (MaintainerAlerter::EXCEPTION_DIGEST_MAX_LINES + 4).' new exceptions')
            && str_ends_with($message, "\n- and 4 more")
            && substr_count($message, "\n- RuntimeException") === MaintainerAlerter::EXCEPTION_DIGEST_MAX_LINES,
    ));

    app(MaintainerAlerter::class)->exceptionDigest($entries);
});

// #986: every alert queues its Telegram send instead of making the call
// inline, so a slow/unreachable Telegram can never block the caller.
it('queues the Telegram send instead of calling the client inline', function (): void {
    Bus::fake();
    $client = fakeTelegram();
    adminWithChat(8001);

    $client->shouldNotReceive('sendMessage');

    app(MaintainerAlerter::class)->totalCeilingApproaching(4.0, 5.0);

    Bus::assertDispatched(fn (SendMaintainerAlertJob $job): bool => str_contains($job->message, '80%'));
});

it('telegramBotRejected names the status and pushes once per cooldown window', function (): void {
    Bus::fake();
    adminWithChat(8003);

    $alerter = app(MaintainerAlerter::class);
    $alerter->telegramBotRejected(401);
    $alerter->telegramBotRejected(404);

    Bus::assertDispatchedTimes(SendMaintainerAlertJob::class, 1);
    Bus::assertDispatched(fn (SendMaintainerAlertJob $job): bool => str_contains($job->message, 'status 401'));
});

it('gates the job dispatch itself on the dedupe window, not just the eventual send', function (): void {
    Bus::fake();
    adminWithChat(8002);

    $alerter = app(MaintainerAlerter::class);
    $alerter->totalCeilingApproaching(4.1, 5.0);
    $alerter->totalCeilingApproaching(4.6, 5.0);

    // Not zero (the first trigger is not lost) and not two (no duplicate).
    Bus::assertDispatchedTimes(SendMaintainerAlertJob::class, 1);
});

it('queues a failed-job page once per incident and one recovered line on its next success', function (): void {
    Bus::fake();
    adminWithChat(8101);

    $alerter = app(MaintainerAlerter::class);
    $alerter->jobFailed('RetryOrphanedStravaGrantReleasesJob');
    $alerter->jobFailed('RetryOrphanedStravaGrantReleasesJob');
    $alerter->jobRecovered('RetryOrphanedStravaGrantReleasesJob');
    $alerter->jobRecovered('RetryOrphanedStravaGrantReleasesJob');

    Bus::assertDispatchedTimes(SendMaintainerAlertJob::class, 2);
    Bus::assertDispatched(fn (SendMaintainerAlertJob $job): bool => $job->message === 'Queued job `RetryOrphanedStravaGrantReleasesJob` failed. Check Horizon and the logs.');
    Bus::assertDispatched(fn (SendMaintainerAlertJob $job): bool => $job->message === 'Queued job `RetryOrphanedStravaGrantReleasesJob` recovered: its latest run succeeded.');
});

it('pages a persistent backfill gap once, inline, and recovers when it closes', function (): void {
    Bus::fake();
    $client = fakeTelegram();
    adminWithChat(8102);
    Carbon::setTestNow('2026-10-06 03:30:00');

    $client->shouldReceive('sendMessage')->once()->with(8102, 'Scheduler `weather:backfill`: 3 runs are still missing weather 48 h after ingest. Check the logs.', 5);
    $client->shouldReceive('sendMessage')->once()->with(8102, 'Scheduler `weather:backfill`: every run has its weather again.', 5);

    $alerter = app(MaintainerAlerter::class);
    $alerter->persistentGap('weather:backfill', 'weather', 0);
    $alerter->persistentGap('weather:backfill', 'weather', 3);
    Carbon::setTestNow('2026-10-08 03:30:00');
    $alerter->persistentGap('weather:backfill', 'weather', 1);
    $alerter->persistentGap('weather:backfill', 'weather', 0);

    Bus::assertNotDispatched(SendMaintainerAlertJob::class);
    Carbon::setTestNow();
});

it('singularises a lone persistent gap', function (): void {
    $client = fakeTelegram();
    adminWithChat(8103);

    $client->shouldReceive('sendMessage')->once()->with(8103, 'Scheduler `geo:backfill-locations`: 1 run is still missing location 48 h after ingest. Check the logs.', 5);

    app(MaintainerAlerter::class)->persistentGap('geo:backfill-locations', 'location', 1);
});
