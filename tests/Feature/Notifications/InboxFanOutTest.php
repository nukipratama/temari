<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Enums\NotificationKind;
use App\Models\Activity;
use App\Models\InboxNotification;
use App\Models\RunCard;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use NotificationChannels\WebPush\WebPushChannel;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
});

function fullyWiredUser(): User
{
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'revoked_at' => null]);
    $user->updatePushSubscription('https://push.example/endpoint', str_repeat('a', 87), str_repeat('b', 22));

    return $user->fresh();
}

function expectPushSends(int $times): void
{
    $push = Mockery::mock(WebPushChannel::class);
    $push->shouldReceive('send')->times($times);
    app()->instance(WebPushChannel::class, $push);
}

function postRunAnalysisFor(User $user): Analysis
{
    $activity = Activity::factory()->for($user)->create();

    return doneAnalysisFor(Activity::class, $activity->id, AnalysisType::PostRunSpeech, content: 'steady one.');
}

// The whole point of routing the inbox through ChannelRouter: no call site
// writes an inbox row itself, so nothing can be sent outbound without a record.
it('routes one notify to Telegram, web push and the inbox', function (): void {
    expectPushSends(1);
    $user = fullyWiredUser();
    $analysis = postRunAnalysisFor($user);

    expect(new AnalysisReadyNotification($analysis)->via($user))
        ->toBe([InAppChannel::class, TelegramChannel::class, IdempotentWebPushChannel::class]);

    $user->notify(new AnalysisReadyNotification($analysis));

    Http::assertSentCount(1);

    $row = InboxNotification::query()->firstOrFail();
    expect($row->kind)->toBe(NotificationKind::PostRun)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->dedupe_key)->toBe('analysis:' . $analysis->id);
});

it('still records the inbox row for a user with no outbound channel wired', function (): void {
    expectPushSends(0);
    $user = User::factory()->create();
    $analysis = postRunAnalysisFor($user);

    $user->notify(new AnalysisReadyNotification($analysis));

    Http::assertNothingSent();
    expect(InboxNotification::query()->where('user_id', $user->id)->count())->toBe(1);
});

// A queued notification that exhausts a retry re-runs every channel. The
// outbound channels dedupe on the delivery claim; the inbox dedupes on its own
// unique (user, dedupe key) pair, since streak and unlock rows have no analysis.
it('does not add a second inbox row when the notification is retried', function (): void {
    expectPushSends(1);
    $user = fullyWiredUser();
    $analysis = postRunAnalysisFor($user);

    $user->notify(new AnalysisReadyNotification($analysis));
    $user->notify(new AnalysisReadyNotification($analysis));

    expect(InboxNotification::query()->count())->toBe(1);
});

// "Replay" means the row can re-arm the real reveal, not that it stored a
// screenshot of one: api.cards.replay takes the card id straight from here.
it('carries enough payload to replay the card reveal weeks later', function (): void {
    expectPushSends(1);
    $user = fullyWiredUser();
    $activity = Activity::factory()->for($user)->create();
    $card = RunCard::factory()->for($activity)->create();
    $analysis = doneAnalysisFor(Activity::class, $activity->id, AnalysisType::PostRunSpeech, content: 'steady one.');

    $user->notify(new AnalysisReadyNotification($analysis));

    $payload = InboxNotification::query()->firstOrFail()->payload;

    expect($payload['run_card_id'])->toBe($card->id)
        ->and($payload['activity_id'])->toBe($activity->id)
        ->and($payload['rarity'])->toBe($card->rarity->value);

    $this->actingAs($user)
        ->post(route('api.cards.replay', $payload['run_card_id']))
        ->assertOk();

    expect($user->fresh()->pending_reveal_card_id)->toBe($card->id);
});

// The public demo is the shop window: the notification centre has to be
// populated there, and nothing may leave the app on the shared identity.
it('records the demo inbox row and sends nothing outbound', function (): void {
    expectPushSends(0);
    $demo = fullyWiredUser();
    $demo->forceFill(['is_demo' => true])->save();
    $analysis = postRunAnalysisFor($demo->fresh());

    $demo->fresh()->notify(new AnalysisReadyNotification($analysis));

    Http::assertNothingSent();
    expect(InboxNotification::query()->where('user_id', $demo->id)->count())->toBe(1);
});
