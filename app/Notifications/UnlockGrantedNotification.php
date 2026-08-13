<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\User;
use App\Notifications\Messages\InboxMessage;
use App\Services\Notifications\ChannelRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Records an unlock in the inbox. Until now the celebration was a session flash
 * from {@see \App\Actions\Gamification\GrantEligibleUnlocksAction}, which meant
 * an unlock granted during a background ingest was never seen at all, and one
 * seen in passing was gone.
 *
 * Inbox-only on purpose: unlocks arrive in batches and are earned rather than
 * time-sensitive, so pushing each one to Telegram and the lock screen would be
 * new noise. The payload mirrors the flash exactly, so the same takeover can
 * replay from the inbox weeks later.
 */
class UnlockGrantedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    /**
     * @param  array{unlock_key: string, name: string, icon: string, is_major: bool}  $celebration
     */
    public function __construct(public readonly array $celebration)
    {
    }

    /**
     * @return array<int, class-string>
     */
    public function via(User $notifiable): array
    {
        if ($notifiable->is_demo) {
            return [];
        }

        return app(ChannelRouter::class)->inAppOnly();
    }

    public function toInbox(User $notifiable): InboxMessage
    {
        return new InboxMessage(
            kind: NotificationKind::Unlock,
            title: 'Unlocked: ' . $this->celebration['name'],
            payload: $this->celebration,
            dedupeKey: 'unlock:' . $this->celebration['unlock_key'],
        );
    }
}
