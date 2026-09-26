<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Jobs\Notifications\RetryStaleWebPushNotificationJob;
use App\Models\AI\Analysis;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Notifications\NotificationDeliveryClaim;
use App\Services\Telegram\NotificationEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;

uses(RefreshDatabase::class);

it('recovers stale delivery claims for both outbound channels', function (): void {
    $telegram = NotificationDelivery::query()->create([
        'analysis_id' => Analysis::factory()->create(['discriminator' => (string) Str::uuid()])->id,
        'channel' => 'telegram',
        'status' => NotificationDeliveryStatus::Pending,
        'created_at' => now()->subMinutes(16),
        'claimed_at' => now()->subMinutes(16),
        'claim_version' => 1,
    ]);
    $webpush = NotificationDelivery::query()->create([
        'analysis_id' => Analysis::factory()->create(['discriminator' => (string) Str::uuid()])->id,
        'channel' => 'webpush',
        'status' => NotificationDeliveryStatus::Pending,
        'created_at' => now()->subMinutes(16),
        'claimed_at' => now()->subMinutes(16),
        'claim_version' => 1,
    ]);
    Queue::fake();

    $this->artisan('notifications:recover-deliveries')
        ->expectsOutput('Recovered stale deliveries: 1 web push re-armed, 1 Telegram abandoned.')
        ->assertExitCode(0);

    expect($telegram->fresh()->status)->toBe(NotificationDeliveryStatus::Abandoned)
        ->and($telegram->fresh()->claim_version)->toBe(2)
        ->and($webpush->fresh()->status)->toBe(NotificationDeliveryStatus::Pending)
        ->and($webpush->fresh()->claim_version)->toBe(2)
        ->and($webpush->fresh()->claimed_at)->toBeNull();

    Queue::assertPushed(RetryStaleWebPushNotificationJob::class, 1);
});

it('queues and runs a web-push retry after the stale cutoff', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $analysis = Analysis::factory()->done('Your run is in.')->create([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'discriminator' => null,
    ]);
    NotificationDelivery::query()->create([
        'analysis_id' => $analysis->id,
        'channel' => 'webpush',
        'status' => NotificationDeliveryStatus::Pending,
        'created_at' => now()->subMinutes(16),
        'claimed_at' => now()->subMinutes(16),
        'claim_version' => 1,
    ]);
    Queue::fake();

    $this->artisan('notifications:recover-deliveries')
        ->expectsOutput('Recovered stale deliveries: 1 web push re-armed, 0 Telegram abandoned.')
        ->assertExitCode(0);

    $job = Queue::pushed(RetryStaleWebPushNotificationJob::class)->first();
    expect($job)->toBeInstanceOf(RetryStaleWebPushNotificationJob::class)
        ->and($job->analysisId)->toBe($analysis->id);

    $webPush = Mockery::mock(WebPushChannel::class);
    $webPush->shouldReceive('send')->once();
    app()->instance(WebPushChannel::class, $webPush);
    $job->handle(app(NotificationDeliveryClaim::class), app(NotificationEligibility::class));
    $job->handle(app(NotificationDeliveryClaim::class), app(NotificationEligibility::class));

    $this->assertDatabaseCount('notification_deliveries', 1);
    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysis->id,
        'channel' => 'webpush',
        'status' => NotificationDeliveryStatus::Sent->value,
        'claim_version' => 3,
    ]);
});
