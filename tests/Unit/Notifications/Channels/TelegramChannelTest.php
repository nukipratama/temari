<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Jobs\AI\SendMaintainerAlertJob;
use App\Services\Notifications\NotificationDeliveryClaim;
use App\Services\Notifications\ChannelRouter;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Models\AI\Analysis;
use App\Models\NotificationPreference;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Messages\TelegramMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
});

function fakeTelegramOk(): void
{
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
}

/**
 * Drive the channel with a controlled message via a stub notification, so the
 * channel's delivery/idempotency/revocation behaviour is tested in isolation
 * from any specific notification's toTelegram().
 */
function channelSend(User $user, TelegramMessage $message): void
{
    $notification = new class ($message) extends Notification {
        public function __construct(private readonly TelegramMessage $message)
        {
        }

        public function toTelegram(User $notifiable): TelegramMessage
        {
            return $this->message;
        }
    };

    app(TelegramChannel::class)->send($user, $notification);
}

function connectedUser(array $attributes = []): User
{
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, ...$attributes]);

    return $user;
}

it('sends a text message to the chat', function (): void {
    fakeTelegramOk();
    $user = connectedUser();

    channelSend($user, new TelegramMessage(text: 'Halo dunia'));

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/sendMessage')
        && $request['chat_id'] === 4242
        && str_contains((string) $request['text'], 'Halo dunia'));
});

it('sends nothing without a connection', function (): void {
    fakeTelegramOk();

    channelSend(User::factory()->create(), new TelegramMessage(text: 'Halo'));

    Http::assertNothingSent();
});

it('sends nothing over a revoked connection', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->revoked()->create();

    channelSend($user, new TelegramMessage(text: 'Halo'));

    Http::assertNothingSent();
});

it('skips a queued Telegram send when muted and does not claim it', function (): void {
    fakeTelegramOk();
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;

    expect(app(ChannelRouter::class)->channelsFor($user))->toContain(TelegramChannel::class);
    NotificationPreference::factory()->for($user)->create(['telegram_enabled' => false]);

    channelSend($user, new TelegramMessage(text: 'Muted', deliveryKey: $analysisId));

    Http::assertNothingSent();
    $this->assertDatabaseMissing('notification_deliveries', ['analysis_id' => $analysisId, 'channel' => 'telegram']);
});

it('sends a queued Telegram message to the current chat after a relink', function (): void {
    fakeTelegramOk();
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;

    expect(app(ChannelRouter::class)->channelsFor($user))->toContain(TelegramChannel::class);
    $user->telegramConnection()->update(['chat_id' => 5252]);

    channelSend($user, new TelegramMessage(text: 'Current link', deliveryKey: $analysisId));

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['chat_id'] === 5252);
    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysisId,
        'channel' => 'telegram',
        'status' => NotificationDeliveryStatus::Sent->value,
    ]);
});

it('claims a keyed delivery and is idempotent across repeats', function (): void {
    fakeTelegramOk();
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;

    channelSend($user, new TelegramMessage(text: 'Sekali saja', deliveryKey: $analysisId));
    channelSend($user, new TelegramMessage(text: 'Sekali saja', deliveryKey: $analysisId));

    Http::assertSentCount(1);
    $this->assertDatabaseHas('notification_deliveries', ['analysis_id' => $analysisId, 'channel' => 'telegram']);
    $this->assertDatabaseCount('notification_deliveries', 1);
});

it('settles the keyed claim as failed with its error so a retry can resend', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'boom'], 500)]);
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;

    expect(fn () => channelSend($user, new TelegramMessage(text: 'Gagal', deliveryKey: $analysisId)))
        ->toThrow(TelegramApiException::class);

    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysisId,
        'channel' => 'telegram',
        'status' => NotificationDeliveryStatus::Failed->value,
    ]);
    expect(app(NotificationDeliveryClaim::class)->claim($analysisId, 'telegram'))->toBe(2);
});

it('abandons a keyed delivery whose connection failed, without a retry or a retake', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;

    channelSend($user, new TelegramMessage(text: 'Timed out', deliveryKey: $analysisId));

    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysisId,
        'channel' => 'telegram',
        'status' => NotificationDeliveryStatus::Abandoned->value,
        'claim_version' => 1,
    ]);
    expect(app(NotificationDeliveryClaim::class)->claim($analysisId, 'telegram'))->toBeNull()
        ->and($user->telegramConnection->fresh()->isRevoked())->toBeFalse();
});

it('rethrows a keyless delivery whose connection failed, so its retry can resend', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
    $user = connectedUser();

    expect(fn () => channelSend($user, new TelegramMessage(text: 'Nudge')))
        ->toThrow(TelegramApiException::class);

    expect(DB::table('notification_deliveries')->count())->toBe(0);
});

it('revokes the connection and does not retry when the bot is blocked (403)', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;

    channelSend($user, new TelegramMessage(text: 'Diblokir', deliveryKey: $analysisId));

    expect($user->telegramConnection->fresh()->isRevoked())->toBeTrue();
});

it('logs when a newer claim fences its send result', function (): void {
    Log::spy();
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;
    Http::fake(['api.telegram.org/*' => function () use ($analysisId) {
        DB::table('notification_deliveries')
            ->where('analysis_id', $analysisId)
            ->where('channel', 'telegram')
            ->update(['claim_version' => 2]);

        return Http::response(['ok' => true, 'result' => true]);
    }]);

    channelSend($user, new TelegramMessage(text: 'Overtaken', deliveryKey: $analysisId));

    Log::shouldHaveReceived('info')->once()->with('telegram.delivery_record.fenced', [
        'delivery_key' => $analysisId,
        'claim_version' => 1,
    ]);
});

it('abandons a stale claim without sending again and fences its late finisher', function (): void {
    fakeTelegramOk();
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $version = $claim->claim($analysisId, 'telegram');
    DB::table('notification_deliveries')
        ->where('analysis_id', $analysisId)
        ->where('channel', 'telegram')
        ->update(['claimed_at' => now()->subMinutes(16)]);

    expect($claim->recoverStale())->toBe(['webpush_retries' => [], 'telegram_abandoned' => 1]);
    channelSend($user, new TelegramMessage(text: 'No duplicate', deliveryKey: $analysisId));

    Http::assertSentCount(0);
    expect($claim->markSent($analysisId, 'telegram', $version))->toBeFalse();
    $this->assertDatabaseCount('notification_deliveries', 1);
    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysisId,
        'channel' => 'telegram',
        'status' => NotificationDeliveryStatus::Abandoned->value,
        'claim_version' => 2,
    ]);
});

it('sends a keyless message (streak / test) without touching the deliveries table', function (): void {
    fakeTelegramOk();
    $user = connectedUser();

    channelSend($user, new TelegramMessage(text: 'Nudge'));

    Http::assertSentCount(1);
    expect(DB::table('notification_deliveries')->count())->toBe(0);
});

it('still revokes on a permanent failure for a keyless message', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: user is deactivated'], 403)]);
    $user = connectedUser();

    channelSend($user, new TelegramMessage(text: 'Nudge'));

    expect($user->telegramConnection->fresh()->isRevoked())->toBeTrue();
});

it('revokes the connection when the chat is not found (400)', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);
    $user = connectedUser();

    channelSend($user, new TelegramMessage(text: 'Hilang'));

    expect($user->telegramConnection->fresh()->isRevoked())->toBeTrue();
});

it('keeps the link, alerts the maintainer once, and rethrows on a bad bot token', function (int $status, string $description): void {
    Bus::fake([SendMaintainerAlertJob::class]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => $description], $status)]);
    $first = connectedUser();
    $second = connectedUser(['chat_id' => 4343]);
    $analysisId = Analysis::factory()->create()->id;

    expect(fn () => channelSend($first, new TelegramMessage(text: 'Token', deliveryKey: $analysisId)))
        ->toThrow(TelegramApiException::class);
    expect(fn () => channelSend($second, new TelegramMessage(text: 'Token')))
        ->toThrow(TelegramApiException::class);

    expect($first->telegramConnection->fresh()->isRevoked())->toBeFalse()
        ->and($second->telegramConnection->fresh()->isRevoked())->toBeFalse();
    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysisId,
        'status' => NotificationDeliveryStatus::Failed->value,
    ]);
    Bus::assertDispatchedTimes(SendMaintainerAlertJob::class, 1);
})->with([
    '401 unauthorized' => [401, 'Unauthorized'],
    '404 not found' => [404, 'Not Found'],
]);

it('keeps the link and does not retry when Telegram rejects the message itself', function (string $description): void {
    Bus::fake([SendMaintainerAlertJob::class]);
    Log::spy();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => $description], 400)]);
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;

    channelSend($user, new TelegramMessage(text: 'Rusak', deliveryKey: $analysisId));

    expect($user->telegramConnection->fresh()->isRevoked())->toBeFalse();
    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysisId,
        'status' => NotificationDeliveryStatus::Failed->value,
    ]);
    Log::shouldHaveReceived('warning')->with('telegram.send.rejected', Mockery::type('array'))->once();
    Bus::assertNothingDispatched();
})->with([
    'parse error' => ["Bad Request: can't parse entities"],
    'empty text' => ['Bad Request: message text is empty'],
    'too long' => ['Bad Request: message is too long'],
]);

it('keeps the link on a 403 that is not about this chat', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: something else'], 403)]);
    $user = connectedUser();

    channelSend($user, new TelegramMessage(text: 'Nudge'));

    expect($user->telegramConnection->fresh()->isRevoked())->toBeFalse();
});
