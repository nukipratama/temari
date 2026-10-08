<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Exceptions\Notifications\UnrestorableHeldNotificationException;
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
use Throwable;

/**
 * Outside quiet hours, re-queues every held send, oldest first, as the same
 * one-channel job Laravel queued at trigger time. Each row is
 * deleted in the transaction that queues it, so a re-run finds nothing to send
 * again; the inbox dedupe key and the delivery claim cover a crash in between.
 * A row whose payload can never be restored is dropped, a failure to dispatch
 * a restored one leaves its row held for the next tick, and either fails the run.
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
        $failed = 0;

        HeldNotification::query()
            ->lazyById(100)
            ->each(function (HeldNotification $held) use (&$released, &$failed): void {
                try {
                    $released += (int) $this->release($held);
                } catch (UnrestorableHeldNotificationException $e) {
                    $failed++;
                    Log::error('notifications.held.unrestorable', ['held_id' => $held->id, 'class' => $e->storedClass]);
                    $held->delete();
                } catch (Throwable $e) {
                    $failed++;
                    Log::error('notifications.held.release_failed', ['held_id' => $held->id, 'exception' => $e::class, 'error' => $e->getMessage()]);
                }
            });

        Log::info('notifications.held.released', ['released' => $released, 'failed' => $failed]);
        $this->info("Released {$released} held notifications.");

        if ($failed > 0) {
            $this->error("{$failed} held notifications failed to release.");

            return self::FAILURE;
        }

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
        try {
            $restored = unserialize($serialized);
        } catch (Throwable $e) {
            throw $e instanceof ModelNotFoundException ? $e : UnrestorableHeldNotificationException::unparseable($serialized, $e);
        }

        if (! $restored instanceof Notification) {
            throw UnrestorableHeldNotificationException::from($restored);
        }

        return $restored;
    }
}
