<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Models\HeldNotification;
use App\Models\User;
use App\Services\Notifications\QuietHours;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Outside quiet hours, re-queues every held send, oldest first, as the same
 * one-channel job Laravel queued at trigger time. Each row is
 * deleted in the transaction that queues it, so a re-run finds nothing to send
 * again; the inbox dedupe key and the delivery claim cover a crash in between.
 */
#[Signature('notifications:release-held')]
#[Description('Release the notifications held during quiet hours, oldest first')]
class ReleaseHeldNotificationsCommand extends Command
{
    public function handle(): int
    {
        if (QuietHours::inEffect()) {
            $this->info('Quiet hours: nothing released.');

            return self::SUCCESS;
        }

        $released = 0;

        HeldNotification::query()
            ->lazyById(100)
            ->each(function (HeldNotification $held) use (&$released): void {
                $released += (int) $this->release($held);
            });

        Log::info('notifications.held.released', ['released' => $released]);
        $this->info("Released {$released} held notifications.");

        return self::SUCCESS;
    }

    private function release(HeldNotification $held): bool
    {
        return DB::transaction(function () use ($held): bool {
            if (HeldNotification::query()->whereKey($held->id)->delete() === 0) {
                return false;
            }

            try {
                $notification = self::restore($held->notification);
            } catch (ModelNotFoundException $e) {
                Log::warning('notifications.held.subject_missing', ['held_id' => $held->id, 'model' => $e->getModel()]);

                return false;
            }

            if (property_exists($notification, 'heldAt')) {
                $notification->heldAt = $held->held_at;
            }

            $user = new Collection([User::query()->findOrFail($held->user_id)]);
            Bus::dispatch(new SendQueuedNotifications($user, $notification, [$held->channel]));

            return true;
        });
    }

    private static function restore(string $serialized): Notification
    {
        return unserialize($serialized);
    }
}
