<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\InboxNotification;
use App\Models\NotificationPreference;
use App\Models\RunCard;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Messages\PushBody;
use App\Notifications\Messages\TelegramMessage;
use App\Services\AI\AnalysisType;
use App\Services\Telegram\AnalysisMessagePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\Encryption;
use NotificationChannels\WebPush\WebPushChannel;

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

function viaFor(Analysis $analysis, User $user): array
{
    return new AnalysisReadyNotification($analysis)->via($user);
}

// --- via() gating (automatic path) -----------------------------------------
//
// The two gates are different kinds of thing, and the inbox splits them apart.
// A *whether* gate (demo, master switch, recency) suppresses the notification
// entirely, so nothing is recorded either. A *where* gate (no connection,
// revoked, muted, no bot token) only removes an outbound channel, and the inbox
// still keeps the record.

it('routes to the Telegram channel for a connected, opted-in, recent run', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();

    expect(viaFor(postRunAnalysis($user), $user))->toBe([InAppChannel::class, TelegramChannel::class]);
});

// The public demo shows the notification centre populated, so it is routed to
// the inbox. It is never interrupted: no Telegram thread, no lock screen, even
// with a connection wired onto the shared identity.
it('routes the demo user to the inbox alone, never outbound', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($user)->create();

    expect(viaFor(postRunAnalysis($user), $user))->toBe([InAppChannel::class]);
});

it('routes nowhere when the notification master switch is off', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect(viaFor(postRunAnalysis($user), $user))->toBe([]);
});

it('rechecks current preferences before a queued channel sends', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');
    $analysis = postRunAnalysis($user);
    Queue::fake();

    $user->notify(new AnalysisReadyNotification($analysis));

    $job = Queue::pushed(SendQueuedNotifications::class)->first(
        fn (SendQueuedNotifications $queued): bool => $queued->channels === [IdempotentWebPushChannel::class],
    );
    expect($job)->toBeInstanceOf(SendQueuedNotifications::class);

    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);
    $webPush = Mockery::mock(WebPushChannel::class);
    $webPush->shouldNotReceive('send');
    app()->instance(WebPushChannel::class, $webPush);

    $job->handle(app(ChannelManager::class));

    $this->assertDatabaseMissing('notification_deliveries', [
        'analysis_id' => $analysis->id,
        'channel' => 'webpush',
    ]);
});

it('preserves a queued inbox notification when outbound preferences change', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');
    $analysis = postRunAnalysis($user);
    Queue::fake();

    $user->notify(new AnalysisReadyNotification($analysis));

    $inboxJob = Queue::pushed(SendQueuedNotifications::class)->first(
        fn (SendQueuedNotifications $queued): bool => $queued->channels === [InAppChannel::class],
    );
    $pushJob = Queue::pushed(SendQueuedNotifications::class)->first(
        fn (SendQueuedNotifications $queued): bool => $queued->channels === [IdempotentWebPushChannel::class],
    );
    expect($inboxJob)->toBeInstanceOf(SendQueuedNotifications::class)
        ->and($pushJob)->toBeInstanceOf(SendQueuedNotifications::class);

    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);
    $webPush = Mockery::mock(WebPushChannel::class);
    $webPush->shouldNotReceive('send');
    app()->instance(WebPushChannel::class, $webPush);

    $channelManager = app(ChannelManager::class);
    $pushJob->handle($channelManager);
    $inboxJob->handle($channelManager);

    $this->assertDatabaseHas('notifications', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('notification_deliveries', [
        'analysis_id' => $analysis->id,
        'channel' => 'webpush',
    ]);
});

it('routes to the inbox alone without a connection', function (): void {
    $user = User::factory()->create();

    expect(viaFor(postRunAnalysis($user), $user))->toBe([InAppChannel::class]);
});

it('routes to the inbox alone over a revoked connection', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->revoked()->create();

    expect(viaFor(postRunAnalysis($user), $user))->toBe([InAppChannel::class]);
});

it('routes to the inbox alone when the bot token is unconfigured', function (): void {
    config(['services.telegram.bot_token' => '']);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();

    expect(viaFor(postRunAnalysis($user), $user))->toBe([InAppChannel::class]);
});

it('routes nowhere for an automatic push older than the max age', function (): void {
    config(['services.telegram.notify_max_age_days' => 3]);
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();

    expect(viaFor(postRunAnalysis($user, daysAgo: 10), $user))->toBe([]);
});

it('routes to web push for a subscribed user with a recent analysis', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(viaFor(postRunAnalysis($user), $user))->toBe([InAppChannel::class, IdempotentWebPushChannel::class]);
});

it('routes nowhere to web push when the notification master switch is off', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect(viaFor(postRunAnalysis($user), $user))->toBe([]);
});

it('routes to both channels when Telegram and web push are both wired', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(viaFor(postRunAnalysis($user), $user))->toBe([InAppChannel::class, TelegramChannel::class, IdempotentWebPushChannel::class]);
});

it('does not route to web push for an old automatic analysis (recency)', function (): void {
    config(['services.telegram.notify_max_age_days' => 3]);
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(viaFor(postRunAnalysis($user, daysAgo: 10), $user))->toBe([]);
});

it('reaches no outbound channel for the demo user', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($demo)->create();

    expect(viaFor(postRunAnalysis($demo), $demo))->toBe([InAppChannel::class]);
});

it('reaches no outbound channel over a revoked connection', function (): void {
    $revoked = User::factory()->create();
    TelegramConnection::factory()->for($revoked)->revoked()->create();

    expect(viaFor(postRunAnalysis($revoked), $revoked))->toBe([InAppChannel::class]);
});

// --- toTelegram() message building -----------------------------------------

it('builds a Telegram message carrying the narration and the delivery key', function (): void {
    $user = User::factory()->create();
    $analysis = postRunAnalysis($user, 'Pace konsisten.');

    $message = new AnalysisReadyNotification($analysis)->toTelegram($user);

    expect($message)->toBeInstanceOf(TelegramMessage::class)
        ->and($message->text)->toContain('Pace konsisten.')
        ->and($message->deliveryKey)->toBe($analysis->id);
});

it('sends the post-run as text with the run link', function (): void {
    $user = User::factory()->create();
    $analysis = postRunAnalysis($user);
    RunCard::factory()->create(['activity_id' => $analysis->subject_id, 'rarity' => 'epic']);

    $message = new AnalysisReadyNotification($analysis)->toTelegram($user);

    expect($message->text)->toContain(route('activities.show', $analysis->subject_id));
});

it('builds a web push message with the dynamic title, body, tap-through url, and high urgency', function (): void {
    $user = User::factory()->create();
    $analysis = postRunAnalysis($user, 'Pace konsisten.');
    $notification = new AnalysisReadyNotification($analysis);

    $message = $notification->toWebPush($user, $notification);
    $payload = $message->toArray();

    expect($payload['title'])->toEndWith('run is in')
        ->and($payload['body'])->toContain('Pace konsisten.')
        ->and($payload['data'])->toBe(['url' => route('activities.show', $analysis->subject_id), 'unread' => 0])
        ->and($message->getOptions())->toBe(['urgency' => 'high', 'TTL' => 3 * 86400]);
});

it('keeps a long, emoji-heavy narration under the push payload limit, and whole in the inbox', function (): void {
    $user = User::factory()->create();
    $narration = str_repeat('honestly the legs were heavier than the pace says 🔥🔥. you held 5:40 through the middle third ✨ and only let it slip on the climb back, which is fine. tomorrow stays easy 🛌 though, no heroics. the week still has room for one more quality day if the legs come round 🔥. ', 40);
    $notification = new AnalysisReadyNotification(postRunAnalysis($user, $narration));

    $payload = json_encode($notification->toWebPush($user, $notification)->toArray(), JSON_THROW_ON_ERROR);

    expect(strlen(json_encode(['body' => $narration], JSON_THROW_ON_ERROR)))->toBeGreaterThan(Encryption::MAX_PAYLOAD_LENGTH)
        ->and(strlen($payload))->toBeLessThan(Encryption::MAX_PAYLOAD_LENGTH)
        ->and(mb_strlen(json_decode($payload, true)['body']))->toBeLessThanOrEqual(PushBody::MAX_CHARS)
        ->and($notification->toInbox($user)->body)->toBe(trim($narration));
});

// --- toInbox() ---------------------------------------------------------------

it('keys the inbox row on the analysis, so a re-analysis adds no second row', function (): void {
    $user = User::factory()->create();
    $analysis = postRunAnalysis($user, 'Pace konsisten.');

    $automatic = new AnalysisReadyNotification($analysis)->toInbox($user);

    expect($automatic->dedupeKey)->toBe('analysis:'.$analysis->id)
        ->and($automatic->kind)->toBe(NotificationKind::PostRun)
        ->and($automatic->body)->toBe('Pace konsisten.')
        ->and($automatic->subjectType)->toBe(Activity::class)
        ->and($automatic->subjectId)->toBe($analysis->subject_id);
});

it('carries the card id and rarity so the reveal can be replayed', function (): void {
    $user = User::factory()->create();
    $analysis = postRunAnalysis($user);
    $card = RunCard::factory()->create(['activity_id' => $analysis->subject_id, 'rarity' => 'epic']);

    expect(new AnalysisReadyNotification($analysis)->toInbox($user)->payload)->toBe([
        'analysis_id' => $analysis->id,
        'url' => route('activities.show', $analysis->subject_id),
        'activity_id' => $analysis->subject_id,
        'run_card_id' => $card->id,
        'rarity' => 'epic',
    ]);
});

it('leaves the card fields null when the run has no card', function (): void {
    $user = User::factory()->create();
    $payload = new AnalysisReadyNotification(postRunAnalysis($user))->toInbox($user)->payload;

    expect($payload['run_card_id'])->toBeNull()
        ->and($payload['rarity'])->toBeNull();
});

it('carries only the deep link for a recap, which has no card to reveal', function (): void {
    $user = User::factory()->create();
    $snapshot = WeeklySnapshot::factory()->for($user)->create();
    $analysis = Analysis::factory()->done('solid week.')->create([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snapshot->id,
        'discriminator' => null,
    ]);

    $message = new AnalysisReadyNotification($analysis)->toInbox($user);

    expect($message->kind)->toBe(NotificationKind::WeeklyRecap)
        ->and($message->payload)->toHaveKeys(['analysis_id', 'url'])
        ->and($message->payload)->not->toHaveKey('run_card_id');
});

it('has no inbox message for an analysis type that never notifies', function (): void {
    $analysis = Analysis::factory()->done('flavor.')->create([
        'analysis_type' => AnalysisType::CardFlavor,
        'subject_type' => RunCard::class,
        'subject_id' => RunCard::factory()->create()->id,
        'discriminator' => null,
    ]);

    expect(new AnalysisReadyNotification($analysis)->toInbox(User::factory()->create()))->toBeNull();
});

it('sends on the surviving channel when only one is muted', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');
    NotificationPreference::factory()->for($user)->create(['telegram_enabled' => false]);

    expect(viaFor(postRunAnalysis($user), $user->fresh()))->toBe([InAppChannel::class, IdempotentWebPushChannel::class]);
});

// The app-icon badge counts inbox rows, so the push carries the count the
// service worker should show rather than leaving it to count its own tray.
it('carries the inbox unread count for the app-icon badge', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->count(3)->create();
    $notification = new AnalysisReadyNotification(postRunAnalysis($user));

    expect($notification->toWebPush($user, $notification)->toArray()['data']['unread'])->toBe(3);
});

it('keeps the Telegram text and the web push title in sentence case, whatever the inbox shows', function (): void {
    $user = User::factory()->create();
    $analysis = postRunAnalysis($user, 'Pace konsisten.');
    $title = new AnalysisMessagePresenter()->title($analysis);
    $notification = new AnalysisReadyNotification($analysis);

    expect($title)->toStartWith('Your ')
        ->and($notification->toTelegram($user)->text)->toStartWith($title . "\n\n")
        ->and($notification->toWebPush($user, $notification)->toArray()['title'])->toBe($title);
});
