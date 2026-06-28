<?php

declare(strict_types=1);

use App\Jobs\Telegram\SendTelegramNotificationJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\NotifiableAnalysis;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * Build a done post-run-speech analysis for $user and return its id.
 */
function postRunAnalysisFor(User $user, string $content = 'Mantap!'): int
{
    $activity = Activity::factory()->for($user)->create();

    return Analysis::factory()->done($content)->create([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'discriminator' => null,
    ])->id;
}

function runSend(int $analysisId): void
{
    (new SendTelegramNotificationJob($analysisId))->handle(
        app(NotifiableAnalysis::class),
        app(TelegramClient::class),
    );
}

it('sends the narration to the connected, opted-in user', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'notify_post_run' => true]);
    $analysisId = postRunAnalysisFor($user, 'Pace konsisten.');

    runSend($analysisId);

    Http::assertSent(fn ($request): bool => $request['chat_id'] === 4242
        && str_contains((string) $request['text'], 'Pace konsisten.'));

    $this->assertDatabaseHas('telegram_deliveries', ['analysis_id' => $analysisId]);
});

it('never sends to the demo user', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($user)->create();

    runSend(postRunAnalysisFor($user));

    Http::assertNothingSent();
});

it('respects the per-type opt-out', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['notify_post_run' => false]);

    runSend(postRunAnalysisFor($user));

    Http::assertNothingSent();
});

it('does nothing when the user has no connection', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create();

    runSend(postRunAnalysisFor($user));

    Http::assertNothingSent();
});

it('does not send over a revoked connection', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->revoked()->create();

    runSend(postRunAnalysisFor($user));

    Http::assertNothingSent();
});

it('is idempotent across repeated dispatches', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $analysisId = postRunAnalysisFor($user);

    runSend($analysisId);
    runSend($analysisId);

    Http::assertSentCount(1);
});

it('releases the delivery claim when the send fails so a retry can resend', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'boom'], 500)]);

    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $analysisId = postRunAnalysisFor($user);

    expect(fn () => runSend($analysisId))->toThrow(TelegramApiException::class);

    $this->assertDatabaseMissing('telegram_deliveries', ['analysis_id' => $analysisId]);
});

function runForceSend(int $analysisId): void
{
    (new SendTelegramNotificationJob($analysisId, force: true))->handle(
        app(NotifiableAnalysis::class),
        app(TelegramClient::class),
    );
}

it('force-sends even when the user has opted out of post-run notifications', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'notify_post_run' => false]);
    $analysisId = postRunAnalysisFor($user, 'Manual push.');

    runForceSend($analysisId);

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Manual push.'));
    // A manual push never claims the once-only delivery row, so it can re-send.
    $this->assertDatabaseMissing('telegram_deliveries', ['analysis_id' => $analysisId]);
});

it('force-sends again even when the activity was already delivered', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'notify_post_run' => true]);
    $analysisId = postRunAnalysisFor($user);
    DB::table('telegram_deliveries')->insert(['analysis_id' => $analysisId, 'created_at' => now()]);

    runForceSend($analysisId);

    Http::assertSentCount(1);
});

it('force still never sends to the demo user or a revoked connection', function (): void {
    fakeTelegramOk();
    $demo = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($demo)->create();
    runForceSend(postRunAnalysisFor($demo));

    $revoked = User::factory()->create();
    TelegramConnection::factory()->for($revoked)->revoked()->create();
    runForceSend(postRunAnalysisFor($revoked));

    Http::assertNothingSent();
});

it('force-send swallows a Telegram failure (one-shot, no retry) and never claims a delivery', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'boom'], 500)]);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'notify_post_run' => true]);
    $analysisId = postRunAnalysisFor($user);

    // Unlike the automatic path, a force-send does not re-throw (so Horizon
    // won't retry and duplicate the manual push).
    runForceSend($analysisId);

    $this->assertDatabaseMissing('telegram_deliveries', ['analysis_id' => $analysisId]);
});
