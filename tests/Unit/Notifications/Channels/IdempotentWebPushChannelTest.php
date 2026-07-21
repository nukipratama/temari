<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\User;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Services\Notifications\NotificationDeliveryClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use NotificationChannels\WebPush\WebPushChannel;

uses(RefreshDatabase::class);

function idempotentChannel(WebPushChannel $inner): IdempotentWebPushChannel
{
    return new IdempotentWebPushChannel($inner, app(NotificationDeliveryClaim::class));
}

it('claims the analysis on the webpush channel and delegates to the package channel', function (): void {
    $analysis = Analysis::factory()->create();
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->once();

    idempotentChannel($inner)->send(User::factory()->create(), new AnalysisReadyNotification($analysis));

    $this->assertDatabaseHas('notification_deliveries', ['analysis_id' => $analysis->id, 'channel' => 'webpush']);
});

it('is idempotent — a second send for the same analysis does not re-deliver', function (): void {
    $analysis = Analysis::factory()->create();
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->once();
    $channel = idempotentChannel($inner);
    $user = User::factory()->create();

    $channel->send($user, new AnalysisReadyNotification($analysis));
    $channel->send($user, new AnalysisReadyNotification($analysis));
    // The `->once()` expectation asserts the package channel delivered a single time.
});

it('releases the claim when delivery throws so a retry can resend', function (): void {
    $analysis = Analysis::factory()->create();
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->andThrow(new RuntimeException('push boom'));

    expect(fn () => idempotentChannel($inner)->send(User::factory()->create(), new AnalysisReadyNotification($analysis)))
        ->toThrow(RuntimeException::class);

    $this->assertDatabaseMissing('notification_deliveries', ['analysis_id' => $analysis->id, 'channel' => 'webpush']);
});

it('re-delivers a forced send even when the analysis was already claimed', function (): void {
    $analysis = Analysis::factory()->create();
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->twice();
    $channel = idempotentChannel($inner);
    $user = User::factory()->create();

    $channel->send($user, new AnalysisReadyNotification($analysis));
    $channel->send($user, new AnalysisReadyNotification($analysis, force: true));
});

it('records the claim after a forced send so a later automatic push is deduped', function (): void {
    $analysis = Analysis::factory()->create();
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->once();
    $channel = idempotentChannel($inner);
    $user = User::factory()->create();

    $channel->send($user, new AnalysisReadyNotification($analysis, force: true));

    $this->assertDatabaseHas('notification_deliveries', ['analysis_id' => $analysis->id, 'channel' => 'webpush']);

    $channel->send($user, new AnalysisReadyNotification($analysis));
});

it('keeps an existing claim when a forced send throws', function (): void {
    $analysis = Analysis::factory()->create();
    app(NotificationDeliveryClaim::class)->claim($analysis->id, 'webpush');
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->andThrow(new RuntimeException('push boom'));

    expect(fn () => idempotentChannel($inner)->send(User::factory()->create(), new AnalysisReadyNotification($analysis, force: true)))
        ->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('notification_deliveries', ['analysis_id' => $analysis->id, 'channel' => 'webpush']);
});

it('sends a keyless notification (no deliveryKey) without claiming', function (): void {
    $inner = Mockery::mock(WebPushChannel::class);
    $inner->shouldReceive('send')->once();
    $notification = new class () extends Notification {};

    idempotentChannel($inner)->send(User::factory()->create(), $notification);

    expect(DB::table('notification_deliveries')->count())->toBe(0);
});
