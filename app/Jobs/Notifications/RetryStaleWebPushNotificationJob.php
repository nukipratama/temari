<?php

declare(strict_types=1);

namespace App\Jobs\Notifications;

use App\Models\AI\Analysis;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\MorningBriefingNotification;
use App\Services\AI\AnalysisType;
use App\Services\Notifications\NotificationDeliveryClaim;
use App\Services\Telegram\NotificationEligibility;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class RetryStaleWebPushNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $analysisId)
    {
    }

    public function handle(NotificationDeliveryClaim $claim, NotificationEligibility $eligibility): void
    {
        $analysis = Analysis::query()->find($this->analysisId);
        if ($analysis === null) {
            $claim->markRearmedWebPushSkipped($this->analysisId);

            return;
        }

        $user = $eligibility->resolveUser($analysis);
        if ($user === null) {
            $claim->markRearmedWebPushSkipped($this->analysisId);

            return;
        }

        $notification = $analysis->analysis_type === AnalysisType::BriefingMascotVoice
            ? new MorningBriefingNotification($analysis)
            : new AnalysisReadyNotification($analysis);

        Notification::sendNow($user, $notification, [IdempotentWebPushChannel::class]);
        $claim->markRearmedWebPushSkipped($this->analysisId);
    }
}
