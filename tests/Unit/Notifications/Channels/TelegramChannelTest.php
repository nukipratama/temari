<?php

declare(strict_types=1);

use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Models\AI\Analysis;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Messages\TelegramMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

it('sends a photo with the text as caption when a photo is present', function (): void {
    fakeTelegramOk();
    $user = connectedUser();

    channelSend($user, new TelegramMessage(text: 'Caption ini', photoPng: 'png-bytes'));

    Http::assertSent(function ($request): bool {
        $caption = collect($request->data())->firstWhere('name', 'caption')['contents'] ?? null;

        return str_contains((string) $request->url(), '/sendPhoto') && str_contains((string) $caption, 'Caption ini');
    });
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '/sendMessage'));
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

it('claims a keyed delivery and is idempotent across repeats', function (): void {
    fakeTelegramOk();
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;

    channelSend($user, new TelegramMessage(text: 'Sekali saja', deliveryKey: $analysisId));
    channelSend($user, new TelegramMessage(text: 'Sekali saja', deliveryKey: $analysisId));

    Http::assertSentCount(1);
    $this->assertDatabaseHas('notification_deliveries', ['analysis_id' => $analysisId, 'channel' => 'telegram']);
});

it('releases the keyed claim when the send fails so a retry can resend', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'boom'], 500)]);
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;

    expect(fn () => channelSend($user, new TelegramMessage(text: 'Gagal', deliveryKey: $analysisId)))
        ->toThrow(TelegramApiException::class);

    $this->assertDatabaseMissing('notification_deliveries', ['analysis_id' => $analysisId, 'channel' => 'telegram']);
});

it('revokes the connection and does not retry when the bot is blocked (403)', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked'], 403)]);
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;

    channelSend($user, new TelegramMessage(text: 'Diblokir', deliveryKey: $analysisId));

    expect($user->telegramConnection->fresh()->isRevoked())->toBeTrue();
});

it('force-sends even when the delivery row already exists, and records the claim', function (): void {
    fakeTelegramOk();
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;
    DB::table('notification_deliveries')->insert(['analysis_id' => $analysisId, 'channel' => 'telegram', 'created_at' => now()]);

    channelSend($user, new TelegramMessage(text: 'Kirim ulang', deliveryKey: $analysisId, force: true));

    Http::assertSentCount(1);
    $this->assertDatabaseHas('notification_deliveries', ['analysis_id' => $analysisId, 'channel' => 'telegram']);
});

it('force-send swallows a failure (one-shot) and never claims a delivery', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'boom'], 500)]);
    $user = connectedUser();
    $analysisId = Analysis::factory()->create()->id;

    channelSend($user, new TelegramMessage(text: 'Gagal manual', deliveryKey: $analysisId, force: true));

    $this->assertDatabaseMissing('notification_deliveries', ['analysis_id' => $analysisId, 'channel' => 'telegram']);
});

it('sends a keyless message (streak / test) without touching the deliveries table', function (): void {
    fakeTelegramOk();
    $user = connectedUser();

    channelSend($user, new TelegramMessage(text: 'Nudge'));

    Http::assertSentCount(1);
    expect(DB::table('notification_deliveries')->count())->toBe(0);
});

it('still revokes on a permanent failure for a keyless message', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden'], 403)]);
    $user = connectedUser();

    channelSend($user, new TelegramMessage(text: 'Nudge'));

    expect($user->telegramConnection->fresh()->isRevoked())->toBeTrue();
});
