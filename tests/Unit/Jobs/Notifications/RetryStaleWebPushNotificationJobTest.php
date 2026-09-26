<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Jobs\Notifications\RetryStaleWebPushNotificationJob;
use App\Models\AI\Analysis;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Notifications\NotificationDeliveryClaim;
use App\Services\Telegram\NotificationEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NotificationChannels\WebPush\WebPushChannel;

uses(RefreshDatabase::class);

it('sends a re-armed web push once through the current delivery gates', function (): void {
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
        'claimed_at' => null,
        'claim_version' => 2,
    ]);

    $webPush = Mockery::mock(WebPushChannel::class);
    $webPush->shouldReceive('send')->once();
    app()->instance(WebPushChannel::class, $webPush);
    $job = new RetryStaleWebPushNotificationJob($analysis->id);
    $eligibility = app(NotificationEligibility::class);

    $job->handle(app(NotificationDeliveryClaim::class), $eligibility);
    $job->handle(app(NotificationDeliveryClaim::class), $eligibility);

    $this->assertDatabaseCount('notification_deliveries', 1);
    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysis->id,
        'channel' => 'webpush',
        'status' => NotificationDeliveryStatus::Sent->value,
        'claim_version' => 3,
    ]);
});

it('settles a re-armed claim when preferences now suppress the retry', function (): void {
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
        'claimed_at' => null,
        'claim_version' => 2,
    ]);
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    $webPush = Mockery::mock(WebPushChannel::class);
    $webPush->shouldNotReceive('send');
    app()->instance(WebPushChannel::class, $webPush);

    $job = new RetryStaleWebPushNotificationJob($analysis->id);
    $job->handle(
        app(NotificationDeliveryClaim::class),
        app(NotificationEligibility::class),
    );

    $this->assertDatabaseHas('notification_deliveries', [
        'analysis_id' => $analysis->id,
        'channel' => 'webpush',
        'status' => NotificationDeliveryStatus::Failed->value,
        'claim_version' => 2,
        'error' => 'Retry skipped because current preferences or channel eligibility no longer allow web push.',
    ]);
});
