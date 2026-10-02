<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Models\AI\Analysis;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\TestNotification;
use App\Services\Notifications\NotificationDeliveryClaim;
use App\Services\Notifications\ChannelRouter;
use Base64Url\Base64Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\WebPushChannel;

uses(RefreshDatabase::class);

function idempotentChannel(WebPushChannel $inner): IdempotentWebPushChannel
{
    return new IdempotentWebPushChannel($inner, app(NotificationDeliveryClaim::class), app(ChannelRouter::class));
}

function pushUser(): User
{
    $user = User::factory()->create();
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');

    return $user;
}

it('claims the analysis on the webpush channel and delegates to the package channel', function (): void {
    $analysis = Analysis::factory()->create();
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->once();

    idempotentChannel($inner)->send(pushUser(), new AnalysisReadyNotification($analysis));

    $this->assertDatabaseHas('notification_deliveries', ['analysis_id' => $analysis->id, 'channel' => 'webpush']);
    $this->assertDatabaseCount('notification_deliveries', 1);
});

it('is idempotent — a second send for the same analysis does not re-deliver', function (): void {
    $analysis = Analysis::factory()->create();
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->once();
    $channel = idempotentChannel($inner);
    $user = pushUser();

    $channel->send($user, new AnalysisReadyNotification($analysis));
    $channel->send($user, new AnalysisReadyNotification($analysis));
    // The `->once()` expectation asserts the package channel delivered a single time.
});

it('logs when a newer claim fences its send result', function (): void {
    Log::spy();
    $analysis = Analysis::factory()->create();
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->once()->andReturnUsing(function () use ($analysis): array {
        DB::table('notification_deliveries')
            ->where('analysis_id', $analysis->id)
            ->where('channel', 'webpush')
            ->update(['claim_version' => 2]);

        return [];
    });

    idempotentChannel($inner)->send(pushUser(), new AnalysisReadyNotification($analysis));

    Log::shouldHaveReceived('info')->once()->with('webpush.delivery_record.fenced', [
        'delivery_key' => $analysis->id,
        'claim_version' => 1,
    ]);
});

it('skips a queued web push when muted and does not claim it', function (): void {
    $analysis = Analysis::factory()->create();
    $user = User::factory()->create();
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');

    expect(app(ChannelRouter::class)->channelsFor($user))->toContain(IdempotentWebPushChannel::class);
    NotificationPreference::factory()->for($user)->create(['push_enabled' => false]);

    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldNotReceive('send');
    idempotentChannel($inner)->send($user, new AnalysisReadyNotification($analysis, force: true));

    $this->assertDatabaseMissing('notification_deliveries', ['analysis_id' => $analysis->id, 'channel' => 'webpush']);
});

it('settles the claim as failed with its error so a retry can resend', function (): void {
    $analysis = Analysis::factory()->create();
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->andThrow(new RuntimeException('push boom'));

    expect(fn () => idempotentChannel($inner)->send(pushUser(), new AnalysisReadyNotification($analysis)))
        ->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysis->id,
        'channel' => 'webpush',
        'status' => NotificationDeliveryStatus::Failed->value,
        'error' => 'push boom',
    ]);
    expect(app(NotificationDeliveryClaim::class)->claim($analysis->id, 'webpush'))->toBe(2);
});

it('re-delivers a forced send even when the analysis was already claimed', function (): void {
    $analysis = Analysis::factory()->create();
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->twice();
    $channel = idempotentChannel($inner);
    $user = pushUser();

    $channel->send($user, new AnalysisReadyNotification($analysis));
    $channel->send($user, new AnalysisReadyNotification($analysis, force: true));

    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysis->id,
        'channel' => 'webpush',
        'status' => NotificationDeliveryStatus::Sent->value,
        'claim_version' => 2,
    ]);
});

it('records the claim after a forced send so a later automatic push is deduped', function (): void {
    $analysis = Analysis::factory()->create();
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->once();
    $channel = idempotentChannel($inner);
    $user = pushUser();

    $channel->send($user, new AnalysisReadyNotification($analysis, force: true));

    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysis->id,
        'channel' => 'webpush',
        'status' => NotificationDeliveryStatus::Sent->value,
        'claim_version' => 1,
    ]);

    $channel->send($user, new AnalysisReadyNotification($analysis));
});

it('keeps an existing claim when a forced send throws', function (): void {
    $analysis = Analysis::factory()->create();
    app(NotificationDeliveryClaim::class)->claim($analysis->id, 'webpush');
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->andThrow(new RuntimeException('push boom'));

    expect(fn () => idempotentChannel($inner)->send(pushUser(), new AnalysisReadyNotification($analysis, force: true)))
        ->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('notification_deliveries', ['analysis_id' => $analysis->id, 'channel' => 'webpush']);
});

it('sends a keyless notification (no deliveryKey) without claiming', function (): void {
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->once();
    $notification = new class () extends Notification {};

    idempotentChannel($inner)->send(pushUser(), $notification);

    expect(DB::table('notification_deliveries')->count())->toBe(0);
});

it('reclaims and sends a stale web push once with the new claim version', function (): void {
    $analysis = Analysis::factory()->create();
    $claim = app(NotificationDeliveryClaim::class);
    expect($claim->claim($analysis->id, 'webpush'))->toBe(1);
    DB::table('notification_deliveries')
        ->where('analysis_id', $analysis->id)
        ->where('channel', 'webpush')
        ->update(['claimed_at' => now()->subMinutes(16)]);

    expect($claim->recoverStale())->toBe([
        'webpush_retries' => [['analysis_id' => $analysis->id, 'claim_version' => 1]],
        'telegram_abandoned' => 0,
    ]);

    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->once();
    idempotentChannel($inner)->send(pushUser(), new AnalysisReadyNotification($analysis));

    $this->assertDatabaseCount('notification_deliveries', 1);
    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysis->id,
        'channel' => 'webpush',
        'status' => NotificationDeliveryStatus::Sent->value,
        'claim_version' => 2,
    ]);
});

it('delivers an encrypted, VAPID-signed push to the subscription endpoint through the HTTP client', function (): void {
    Http::preventStrayRequests();
    Http::fake(['push.example/*' => Http::response('', 201)]);
    $vapid = VAPID::createVapidKeys();
    config(['webpush.vapid.public_key' => $vapid['publicKey'], 'webpush.vapid.private_key' => $vapid['privateKey']]);
    $device = openssl_pkey_get_details(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]))['ec'];
    $user = User::factory()->create();
    $user->updatePushSubscription(
        'https://push.example/endpoint',
        Base64Url::encode("\x04".str_pad($device['x'], 32, "\0", STR_PAD_LEFT).str_pad($device['y'], 32, "\0", STR_PAD_LEFT)),
        Base64Url::encode(random_bytes(16)),
    );

    app(IdempotentWebPushChannel::class)->send($user, new TestNotification());

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://push.example/endpoint'
        && $request->method() === 'POST'
        && str_starts_with($request->header('Authorization')[0] ?? '', 'vapid t=')
        && $request->header('Content-Encoding') === ['aes128gcm']
        && $request->header('Urgency') === ['high']
        && $request->body() !== '');
});
