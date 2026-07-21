<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\NotificationPreference;
use App\Models\RunCard;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Messages\TelegramMessage;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\RunCardImageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
});

/**
 * A done post-run-speech analysis for $user, whose activity started $daysAgo ago.
 */
function postRunAnalysis(User $user, string $content = 'Mantap!', int $daysAgo = 0): Analysis
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDays($daysAgo)]);

    return Analysis::factory()->done($content)->create([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'discriminator' => null,
    ]);
}

function viaFor(Analysis $analysis, User $user, bool $force = false): array
{
    return new AnalysisReadyNotification($analysis, force: $force)->via($user);
}

// --- via() gating (automatic path) -----------------------------------------

it('routes to the Telegram channel for a connected, opted-in, recent run', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();

    expect(viaFor(postRunAnalysis($user), $user))->toBe([TelegramChannel::class]);
});

it('routes nowhere for the demo user', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($user)->create();

    expect(viaFor(postRunAnalysis($user), $user))->toBe([]);
});

it('routes nowhere when opted out of the type', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    NotificationPreference::factory()->for($user)->create(['post_run' => false]);

    expect(viaFor(postRunAnalysis($user), $user))->toBe([]);
});

it('routes nowhere without a connection', function (): void {
    $user = User::factory()->create();

    expect(viaFor(postRunAnalysis($user), $user))->toBe([]);
});

it('routes nowhere over a revoked connection', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->revoked()->create();

    expect(viaFor(postRunAnalysis($user), $user))->toBe([]);
});

it('routes nowhere when the bot token is unconfigured', function (): void {
    config(['services.telegram.bot_token' => '']);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();

    expect(viaFor(postRunAnalysis($user), $user))->toBe([]);
});

it('routes nowhere for an automatic push older than the max age', function (): void {
    config(['services.telegram.notify_max_age_days' => 3]);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();

    expect(viaFor(postRunAnalysis($user, daysAgo: 10), $user))->toBe([]);
});

// --- via() gating (force path) ---------------------------------------------

it('force routes to Telegram even when opted out', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    NotificationPreference::factory()->for($user)->create(['post_run' => false]);

    expect(viaFor(postRunAnalysis($user), $user, force: true))->toBe([TelegramChannel::class]);
});

it('force bypasses the recency gate for an old run', function (): void {
    config(['services.telegram.notify_max_age_days' => 3]);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    NotificationPreference::factory()->for($user)->create(['post_run' => false]);

    expect(viaFor(postRunAnalysis($user, daysAgo: 10), $user, force: true))->toBe([TelegramChannel::class]);
});

it('routes to web push for a subscribed user with a recent analysis', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(viaFor(postRunAnalysis($user), $user))->toBe([IdempotentWebPushChannel::class]);
});

it('routes nowhere to web push when opted out of the type', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');
    NotificationPreference::factory()->for($user)->create(['post_run' => false]);

    expect(viaFor(postRunAnalysis($user), $user))->toBe([]);
});

it('routes to both channels when Telegram and web push are both wired', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(viaFor(postRunAnalysis($user), $user))->toBe([TelegramChannel::class, IdempotentWebPushChannel::class]);
});

it('does not route to web push for an old automatic analysis (recency)', function (): void {
    config(['services.telegram.notify_max_age_days' => 3]);
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(viaFor(postRunAnalysis($user, daysAgo: 10), $user))->toBe([]);
});

it('force reaches web push even without Telegram', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(viaFor(postRunAnalysis($user), $user, force: true))->toBe([IdempotentWebPushChannel::class]);
});

it('force still routes nowhere for the demo user or a revoked connection', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($demo)->create();
    expect(viaFor(postRunAnalysis($demo), $demo, force: true))->toBe([]);

    $revoked = User::factory()->create();
    TelegramConnection::factory()->for($revoked)->revoked()->create();
    expect(viaFor(postRunAnalysis($revoked), $revoked, force: true))->toBe([]);
});

// --- toTelegram() message building -----------------------------------------

it('builds a Telegram message carrying the narration, the delivery key, and the force flag', function (): void {
    $user = User::factory()->create();
    $analysis = postRunAnalysis($user, 'Pace konsisten.');

    $message = new AnalysisReadyNotification($analysis, force: true)->toTelegram($user);

    expect($message)->toBeInstanceOf(TelegramMessage::class)
        ->and($message->text)->toContain('Pace konsisten.')
        ->and($message->deliveryKey)->toBe($analysis->id)
        ->and($message->force)->toBeTrue();
});

it('attaches the card photo for a post-run whose activity has a card', function (): void {
    app()->instance(RunCardImageRenderer::class, fakeRenderer());
    $user = User::factory()->create();
    $analysis = postRunAnalysis($user);
    RunCard::factory()->create(['activity_id' => $analysis->subject_id, 'rarity' => 'epic']);

    expect(new AnalysisReadyNotification($analysis)->toTelegram($user)->photoPng)->toBe('fake-png-bytes');
});

it('falls back to text when the card render throws', function (): void {
    app()->instance(RunCardImageRenderer::class, fakeRenderer(new RuntimeException('imagick boom')));
    $user = User::factory()->create();
    $analysis = postRunAnalysis($user);
    RunCard::factory()->create(['activity_id' => $analysis->subject_id, 'rarity' => 'epic']);

    expect(new AnalysisReadyNotification($analysis)->toTelegram($user)->photoPng)->toBeNull();
});

it('sends as text when the post-run activity has no card', function (): void {
    $user = User::factory()->create();

    expect(new AnalysisReadyNotification(postRunAnalysis($user))->toTelegram($user)->photoPng)->toBeNull();
});

it('builds a web push message with the dynamic title, body, tap-through url, and high urgency', function (): void {
    $user = User::factory()->create();
    $analysis = postRunAnalysis($user, 'Pace konsisten.');
    $notification = new AnalysisReadyNotification($analysis);

    $message = $notification->toWebPush($user, $notification);
    $payload = $message->toArray();

    expect($payload['title'])->toContain('udah masuk!')
        ->and($payload['body'])->toContain('Pace konsisten.')
        ->and($payload['data'])->toBe(['url' => route('aktivitas.show', $analysis->subject_id)])
        ->and($message->getOptions())->toBe(['urgency' => 'high']);
});

/**
 * A fake renderer returning dummy PNG bytes (or throwing) — the real renderer has
 * its own Imagick suite; this only needs "photo when render succeeds, text when not."
 */
function fakeRenderer(?Throwable $throws = null): RunCardImageRenderer
{
    $renderer = Mockery::mock(RunCardImageRenderer::class);
    $expectation = $renderer->shouldReceive('render');
    $throws !== null ? $expectation->andThrow($throws) : $expectation->andReturn('fake-png-bytes');

    return $renderer;
}

/**
 * The one rule that differs from every other gate: `force: true` skips the
 * recency and per-type opt-in checks, because the user explicitly asked for this
 * send. It cannot skip a channel mute, because that is a routing decision — "do
 * not deliver here, ever" — rather than a per-message one.
 */
it('will not force a send to a muted channel, even though force skips the opt-in', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    NotificationPreference::factory()->for($user)->create([
        'post_run' => false,
        'telegram_enabled' => false,
    ]);

    // post_run is off too, and force would normally override that.
    expect(viaFor(postRunAnalysis($user), $user->fresh(), force: true))->toBe([]);
});

it('forces past the per-type opt-in when the channel is not muted', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    NotificationPreference::factory()->for($user)->create([
        'post_run' => false,
        'telegram_enabled' => true,
    ]);

    expect(viaFor(postRunAnalysis($user), $user->fresh(), force: true))->toBe([TelegramChannel::class]);
});

it('sends on the surviving channel when only one is muted', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');
    NotificationPreference::factory()->for($user)->create(['telegram_enabled' => false]);

    expect(viaFor(postRunAnalysis($user), $user->fresh()))->toBe([IdempotentWebPushChannel::class]);
});
