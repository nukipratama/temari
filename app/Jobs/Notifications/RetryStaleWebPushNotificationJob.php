<?php

declare(strict_types=1);

namespace App\Jobs\Notifications;

use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\AI\Analysis;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\MorningBriefingNotification;
use App\Services\AI\AnalysisType;
use App\Services\Notifications\NotificationDeliveryClaim;
use App\Services\Notifications\QuietHours;
use App\Services\Telegram\NotificationEligibility;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

#[Backoff([30, 120])]
#[Tries(3)]
class RetryStaleWebPushNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $analysisId,
        public readonly int $claimVersion,
    ) {
    }

    public function handle(NotificationDeliveryClaim $claim, NotificationEligibility $eligibility): void
    {
        if (QuietHours::inEffect()) {
            return;
        }

        $analysis = Analysis::query()->find($this->analysisId);
        if ($analysis === null) {
            $claim->markStaleWebPushSkipped($this->analysisId, $this->claimVersion);

            return;
        }

        $user = $eligibility->resolveUser($analysis);
        if ($user === null) {
            $claim->markStaleWebPushSkipped($this->analysisId, $this->claimVersion);

            return;
        }

        $notification = $analysis->analysis_type === AnalysisType::BriefingMascotVoice
            ? new MorningBriefingNotification($analysis)
            : new AnalysisReadyNotification($analysis);

        Notification::sendNow($user, $notification, [IdempotentWebPushChannel::class]);
        $claim->markStaleWebPushSkipped($this->analysisId, $this->claimVersion);
    }
}
