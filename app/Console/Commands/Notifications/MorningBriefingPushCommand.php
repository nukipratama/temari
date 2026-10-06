<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Models\AI\Analysis;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\MorningBriefingNotification;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Notifications\ChannelRouter;
use App\Services\Notifications\UsualRunTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

#[Signature('briefing:morning-push')]
#[Description("Push today's already-generated briefing to each athlete at the time of day they usually run")]
class MorningBriefingPushCommand extends Command
{
    public const int BUCKET_MINUTES = 15;

    public const int CATCH_UP_MINUTES = 60;

    public function handle(ChannelRouter $router, UsualRunTime $usualRunTime): int
    {
        $now = Carbon::now();
        $minuteOfDay = $now->hour * 60 + $now->minute;
        $today = $now->toDateString();

        $users = User::query()
            ->where($router->scopeReachable(...))
            ->whereDoesntHave(
                'notificationPreference',
                fn (Builder $preference): Builder => $preference->where('notifications_enabled', false),
            )
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            $slot = intdiv($usualRunTime->forUser($user->id), self::BUCKET_MINUTES) * self::BUCKET_MINUTES;
            if ($minuteOfDay < $slot || $minuteOfDay - $slot > self::CATCH_UP_MINUTES) {
                continue;
            }

            $briefing = $this->briefingFor($user, $today);
            if ($briefing === null || $this->claimedOnEveryChannel($router, $user, $briefing)) {
                continue;
            }

            $user->notify(new MorningBriefingNotification($briefing));
            $sent++;
        }

        $this->info("Pushed the morning briefing to {$sent} athletes.");

        return self::SUCCESS;
    }

    private function claimedOnEveryChannel(ChannelRouter $router, User $user, Analysis $briefing): bool
    {
        $channels = array_map(ChannelRouter::deliveryChannel(...), $router->outboundOnly($user));
        if ($channels === []) {
            return false;
        }

        $claimed = NotificationDelivery::query()
            ->where('analysis_id', $briefing->id)
            ->whereIn('channel', $channels)
            ->distinct()
            ->count('channel');

        return $claimed === count($channels);
    }

    /**
     * Today's briefing, only once it has actually been narrated. A row still
     * pending or failed is skipped and never generated here: 00:01's kickoff
     * owns generation, and a push is not a reason to spend a token.
     */
    private function briefingFor(User $user, string $today): ?Analysis
    {
        return Analysis::query()
            ->where('subject_type', AnalysisType::BRIEFING_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('analysis_type', AnalysisType::BriefingMascotVoice)
            ->where('discriminator', $today)
            ->where('status', AnalysisStatus::Done)
            ->whereNotNull('content')
            ->first();
    }
}
