<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Models\AI\Analysis;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\PlannedSession;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

#[Signature('briefing:morning-push')]
#[Description("Push today's already-generated briefing to each athlete an hour before they usually run, or at the time they chose")]
class MorningBriefingPushCommand extends Command
{
    public const int BUCKET_MINUTES = 15;

    public const int CATCH_UP_MINUTES = 60;

    public const int LEAD_MINUTES = 60;

    /**
     * The minute of the day this athlete's briefing is due, on the quarter hour
     * at or before their chosen send time, or else an hour before their usual
     * start, never before midnight.
     */
    public static function slotFor(int $userId, ?NotificationPreference $preference): int
    {
        $minute = $preference->briefing_push_minute
            ?? max(0, app(UsualRunTime::class)->forUser($userId) - self::LEAD_MINUTES);

        return intdiv($minute, self::BUCKET_MINUTES) * self::BUCKET_MINUTES;
    }

    public function handle(ChannelRouter $router): int
    {
        $now = Carbon::now();
        $minuteOfDay = $now->hour * 60 + $now->minute;
        $today = $now->toDateString();

        $users = User::query()
            ->where($router->scopeReachable(...))
            ->whereDoesntHave(
                'notificationPreference',
                fn (Builder $preference): Builder => $preference
                    ->where('notifications_enabled', false)
                    ->orWhere('briefing_push_enabled', false),
            )
            ->whereNotIn('id', PlannedSession::query()->select('user_id')->where('date', $today)->where('skipped', true))
            ->with('notificationPreference')
            ->get();

        $due = $users->filter(function (User $user) use ($minuteOfDay): bool {
            $slot = self::slotFor($user->id, $user->notificationPreference);

            return $minuteOfDay >= $slot && $minuteOfDay - $slot <= self::CATCH_UP_MINUTES;
        });

        $briefings = $this->briefingsFor($due, $today);
        $claimedChannels = $this->claimedChannelsFor($briefings);

        $sent = 0;

        foreach ($due as $user) {
            $briefing = $briefings->get($user->id);
            if ($briefing === null || $this->claimedOnEveryChannel($router, $user, $claimedChannels[$briefing->id] ?? [])) {
                continue;
            }

            $user->notify(new MorningBriefingNotification($briefing));
            $sent++;
        }

        $this->info("Pushed the morning briefing to {$sent} athletes.");

        return self::SUCCESS;
    }

    /** @param  list<string>  $claimedChannels */
    private function claimedOnEveryChannel(ChannelRouter $router, User $user, array $claimedChannels): bool
    {
        $channels = array_map(ChannelRouter::deliveryChannel(...), $router->outboundOnly($user));

        return $channels !== [] && array_diff($channels, $claimedChannels) === [];
    }

    /**
     * The delivery channels already claimed per briefing id.
     *
     * @param  Collection<int, Analysis>  $briefings
     * @return array<int, list<string>>
     */
    private function claimedChannelsFor(Collection $briefings): array
    {
        $claimed = [];
        $deliveries = NotificationDelivery::query()->whereIn('analysis_id', $briefings->pluck('id'))->get(['analysis_id', 'channel']);
        foreach ($deliveries as $delivery) {
            $claimed[$delivery->analysis_id][] = $delivery->channel;
        }

        return $claimed;
    }

    /**
     * Today's briefings keyed by athlete id, only once actually narrated. A row
     * still pending or failed is skipped and never generated here: 00:01's
     * kickoff owns generation, and a push is not a reason to spend a token.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, Analysis>
     */
    private function briefingsFor(Collection $users, string $today): Collection
    {
        return Analysis::query()
            ->where('subject_type', AnalysisType::BRIEFING_SUBJECT_TYPE)
            ->whereIn('subject_id', $users->modelKeys())
            ->where('analysis_type', AnalysisType::BriefingMascotVoice)
            ->where('discriminator', $today)
            ->where('status', AnalysisStatus::Done)
            ->whereNotNull('content')
            ->get()
            ->keyBy('subject_id');
    }
}
