<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Models\AI\Analysis;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Services\Notifications\NotificationDeliveryClaim;
use App\Services\Notifications\ChannelRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
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

    expect($claim->recoverStale())->toBe(['webpush_rearmed' => [$analysis->id], 'telegram_abandoned' => 0]);

    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->once();
    idempotentChannel($inner)->send(pushUser(), new AnalysisReadyNotification($analysis));

    $this->assertDatabaseCount('notification_deliveries', 1);
    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysis->id,
        'channel' => 'webpush',
        'status' => NotificationDeliveryStatus::Sent->value,
        'claim_version' => 3,
    ]);
});
