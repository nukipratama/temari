<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single answer to "where can this user actually be reached right now".
 *
 * Before this, six places asked that question and three different answers came
 * back: only `AnalysisReadyNotification` checked that a Telegram bot token was
 * configured, so the other five would happily route to a channel that could not
 * possibly send. Adding a mute flag to each copy is how that drift happened in
 * the first place.
 *
 * Two conditions per channel, and they are different kinds of thing:
 *
 * - **Wired** — is there a live connection or subscription at all, and is the
 *   app configured to use it. Infrastructure.
 * - **Enabled** — has the user muted it. Intent.
 *
 * A muted channel stays wired: the Telegram link is not revoked and the push
 * subscription is not deleted, so un-muting is one tap with no re-auth.
 *
 * This answers *where*, never *whether*. Per-type opt-in and recency stay with
 * `NotifiableAnalysis` and the notifications themselves, because those are
 * per-message questions and this is a routing one. That split is also why a
 * forced send may skip the opt-in but can never skip a mute.
 */
final readonly class ChannelRouter
{
    /**
     * Channels the user can be reached on, as Laravel channel class-strings.
     *
     * @return list<class-string>
     */
    public function channelsFor(User $user): array
    {
        $channels = [];

        if ($this->telegramReachable($user)) {
            $channels[] = TelegramChannel::class;
        }

        if ($this->pushReachable($user)) {
            $channels[] = IdempotentWebPushChannel::class;
        }

        return $channels;
    }

    public function canReach(User $user): bool
    {
        return $this->channelsFor($user) !== [];
    }

    public function telegramReachable(User $user): bool
    {
        if (! $this->telegramConfigured()) {
            return false;
        }

        $connection = $user->telegramConnection;
        if ($connection === null || $connection->isRevoked()) {
            return false;
        }

        return $this->enabled($user, 'telegram_enabled');
    }

    public function pushReachable(User $user): bool
    {
        if (! $user->pushSubscriptions()->exists()) {
            return false;
        }

        return $this->enabled($user, 'push_enabled');
    }

    /**
     * Query-level equivalent of {@see self::canReach()}, for callers that select
     * users in bulk rather than checking one.
     *
     * `StreakRemindCommand` needs this: without it the command enqueues a
     * notification per candidate and each `via()` returns an empty array, which
     * is silent no-op work every Saturday rather than a visible failure.
     *
     * @param  Builder<User>  $query
     */
    public function scopeReachable(Builder $query): void
    {
        $telegramConfigured = $this->telegramConfigured();

        $query->where(function (Builder $reachable) use ($telegramConfigured): void {
            if ($telegramConfigured) {
                $reachable->where(function (Builder $viaTelegram): void {
                    $viaTelegram
                        ->whereIn('id', TelegramConnection::query()->active()->select('user_id'))
                        ->whereDoesntHave(
                            'notificationPreference',
                            fn (Builder $preference): Builder => $preference->where('telegram_enabled', false),
                        );
                });
            }

            $reachable->orWhere(function (Builder $viaPush): void {
                $viaPush
                    ->whereHas('pushSubscriptions')
                    ->whereDoesntHave(
                        'notificationPreference',
                        fn (Builder $preference): Builder => $preference->where('push_enabled', false),
                    );
            });
        });
    }

    private function telegramConfigured(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    /**
     * A missing preference row means all-on, matching the contract the per-type
     * flags already rely on. Adding the mute columns must not mute anyone.
     */
    private function enabled(User $user, string $column): bool
    {
        $preference = $user->notificationPreference;

        return $preference === null || (bool) $preference->{$column};
    }
}
