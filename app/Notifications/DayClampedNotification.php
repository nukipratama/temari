<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\SessionType;
use App\Models\User;
use App\Notifications\Messages\InboxMessage;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Notifications\ChannelRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * A readiness step-down, kept as a record rather than only rendered.
 * {@see \App\Services\Run\Plan\RestClampRecorder} already writes the outcome so
 * compliance can grade the day it asked for; this makes the same moment
 * legible to the athlete, whose only way to learn of it was to open the plan
 * while it still applied.
 *
 * Inbox-only. The clamp is advisory
 * ({@see \App\Services\Run\Plan\ReadinessClamp}) and the briefing path records
 * it at 00:01, so a lock screen is the wrong place for it — and the master
 * switch, which enumerates what it governs, does not name it either.
 */
class DayClampedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly string $date,
        public readonly SessionType $clampedTo,
        public readonly string $note,
    ) {
    }

    /**
     * @return array<int, class-string>
     */
    public function via(User $notifiable): array
    {
        return app(ChannelRouter::class)->inAppOnly();
    }

    public function toInbox(User $notifiable): InboxMessage
    {
        return new InboxMessage(
            kind: NotificationKind::PlanClamp,
            title: $this->title(),
            body: $this->body($notifiable),
            payload: ['url' => route('dashboard')],
            dedupeKey: 'plan_clamp:'.$this->date,
        );
    }

    private function title(): string
    {
        return $this->clampedTo === SessionType::Rest
            ? "Today's a full rest"
            : 'Today eases off';
    }

    /**
     * The clamp's own explanation, in whichever voice has reached it. The
     * templated note is the permanent floor `the-clamp-explains-itself` keeps
     * it as, and it is what a row usually carries: the narration is requested
     * moments before this is queued, so it has rarely landed yet.
     */
    private function body(User $notifiable): string
    {
        return app(PlanNarrationRequester::class)->clampVoiceFor($notifiable, Carbon::parse($this->date)) ?? $this->note;
    }
}
