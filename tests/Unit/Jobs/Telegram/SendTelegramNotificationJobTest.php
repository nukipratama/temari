<?php

declare(strict_types=1);

use App\Jobs\Telegram\SendTelegramNotificationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\RunCardImageRenderer;
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

/**
 * A fake renderer returning dummy PNG bytes — RunCardImageRenderer's own
 * rendering correctness has its own dedicated (real-Imagick) test suite; this
 * job only needs to know "photo when render succeeds, text when it doesn't."
 */
function fakeImageRenderer(?Throwable $throws = null): RunCardImageRenderer
{
    $renderer = Mockery::mock(RunCardImageRenderer::class);
    $expectation = $renderer->shouldReceive('render');
    $throws !== null ? $expectation->andThrow($throws) : $expectation->andReturn('fake-png-bytes');

    return $renderer;
}

function runSend(int $analysisId, ?RunCardImageRenderer $imageRenderer = null): void
{
    new SendTelegramNotificationJob($analysisId)->handle(
        app(NotifiableAnalysis::class),
        app(TelegramClient::class),
        $imageRenderer ?? fakeImageRenderer(),
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

it('sends the post-run notification as a single photo with the narration as caption when the activity has a card', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'notify_post_run' => true]);

    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['distance' => 5_280, 'summary_polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@', 'start_date_local' => now()]);
    RunCard::factory()->for($activity)->create(['rarity' => 'epic', 'special_move' => 'Tendangan Balik']);
    $analysisId = Analysis::factory()->done('Pace konsisten.')->create([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'discriminator' => null,
    ])->id;

    runSend($analysisId);

    Http::assertSent(function ($request): bool {
        $caption = collect($request->data())->firstWhere('name', 'caption')['contents'] ?? null;

        return str_contains((string) $request->url(), '/sendPhoto')
            && str_contains((string) $caption, 'Pace konsisten.');
    });
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '/sendMessage'));
});

it('falls back to a text message when card rendering throws', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'notify_post_run' => true]);

    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    RunCard::factory()->for($activity)->create(['rarity' => 'epic']);
    $analysisId = Analysis::factory()->done('Render gagal, tetap kirim.')->create([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'discriminator' => null,
    ])->id;

    runSend($analysisId, fakeImageRenderer(throws: new RuntimeException('imagick boom')));

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/sendMessage')
        && str_contains((string) $request['text'], 'Render gagal, tetap kirim.'));
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '/sendPhoto'));
});

it('falls back to a text message when the post-run activity has no card', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'notify_post_run' => true]);
    $analysisId = postRunAnalysisFor($user, 'Tanpa kartu.');

    runSend($analysisId);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/sendMessage')
        && str_contains((string) $request['text'], 'Tanpa kartu.'));
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '/sendPhoto'));
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

it('revokes the connection and does not retry when the bot is blocked (403)', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
    $user = User::factory()->create();
    $connection = TelegramConnection::factory()->for($user)->create();
    $analysisId = postRunAnalysisFor($user);

    // A blocked bot is a non-retryable 4xx: the job swallows it (no rethrow) so
    // Horizon won't churn retries, and marks the connection dead like Strava does.
    runSend($analysisId);

    expect($connection->fresh()->isRevoked())->toBeTrue();
});

it('skips the automatic push for a backfilled activity older than the max age', function (): void {
    fakeTelegramOk();
    config(['services.telegram.notify_max_age_days' => 3]);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['notify_post_run' => true]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDays(10)]);
    $analysisId = Analysis::factory()->done('Cerita lama.')->create([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'discriminator' => null,
    ])->id;

    runSend($analysisId);

    Http::assertNothingSent();
    $this->assertDatabaseMissing('telegram_deliveries', ['analysis_id' => $analysisId]);
});

function runForceSend(int $analysisId): void
{
    new SendTelegramNotificationJob($analysisId, force: true)->handle(
        app(NotifiableAnalysis::class),
        app(TelegramClient::class),
        fakeImageRenderer(),
    );
}

it('force-sends even when the user has opted out of post-run notifications', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'notify_post_run' => false]);
    $analysisId = postRunAnalysisFor($user, 'Manual push.');

    runForceSend($analysisId);

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Manual push.'));
    // A manual push records the delivery claim (so a later automatic re-notify is
    // deduped), but can still re-send because it bypasses the claim CHECK.
    $this->assertDatabaseHas('telegram_deliveries', ['analysis_id' => $analysisId]);
});

it('records the delivery claim on a force-send so a later automatic push is deduped', function (): void {
    fakeTelegramOk();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'notify_post_run' => true]);
    $analysisId = postRunAnalysisFor($user);

    runForceSend($analysisId); // manual push claims the delivery row
    runSend($analysisId);      // automatic path for the same row (e.g. a re-analysis)

    // Only the manual push went out; the automatic re-notify found the claim and bailed.
    Http::assertSentCount(1);
    $this->assertDatabaseHas('telegram_deliveries', ['analysis_id' => $analysisId]);
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

it('force-send bypasses the recency gate for an old activity', function (): void {
    fakeTelegramOk();
    config(['services.telegram.notify_max_age_days' => 3]);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'notify_post_run' => true]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDays(10)]);
    $analysisId = Analysis::factory()->done('Cerita lama, kirim manual.')->create([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'discriminator' => null,
    ])->id;

    runForceSend($analysisId);

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Cerita lama, kirim manual.'));
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
