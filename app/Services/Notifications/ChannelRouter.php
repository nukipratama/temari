<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single answer to "where can this user actually be reached right now".
 * Every call site shares this one check rather than each re-deriving its own
 * answer, which is how "reachable" quietly drifts out of sync between callers.
 *
 * Two conditions per **outbound** channel, and they are different kinds of thing:
 *
 * - **Wired** — is there a live connection or subscription at all, and is the
 *   app configured to use it. Infrastructure.
 * - **Enabled** — has the user muted it. Intent.
 *
 * A muted channel stays wired: the Telegram link is not revoked and the push
 * subscription is not deleted, so un-muting is one tap with no re-auth.
 *
 * The in-app inbox has neither condition. It is always reachable, so
 * {@see self::channelsFor()} always includes it while {@see self::canReach()}
 * and {@see self::scopeReachable()} stay about the outbound channels.
 *
 * This answers *where*, never *whether*. Per-type opt-in and recency stay with
 * `NotificationEligibility` and the notifications themselves, because those are
 * per-message questions and this is a routing one. That split is also why a
 * forced send may skip the opt-in but can never skip a mute.
 */
final readonly class ChannelRouter
{
    /**
     * Channels the user can be reached on, as Laravel channel class-strings.
     * The inbox always leads: it needs no wiring, carries no mute, and is the
     * record the other channels are notifications *of*.
     *
     * @return list<class-string>
     */
    public function channelsFor(User $user): array
    {
        return [InAppChannel::class, ...$this->outboundChannelsFor($user)];
    }

    /**
     * The inbox alone, for a notification that is a record rather than an
     * interruption. Still routed here so channel class-strings stay in one place.
     *
     * @return list<class-string>
     */
    public function inAppOnly(): array
    {
        return [InAppChannel::class];
    }

    /**
     * Whether Temari can reach *out* to the user. Deliberately not
     * `channelsFor() !== []`, which the always-on inbox would make trivially
     * true: the callers asking this ("can the test send prove anything", "is
     * this user worth a streak nudge") are asking about the outbound channels.
     */
    public function canReach(User $user): bool
    {
        return $this->outboundChannelsFor($user) !== [];
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
     * `StreakRemindCommand` needs this: the nudge is time-boxed to the rest of
     * the week, so it is only worth sending to someone an outbound channel can
     * actually interrupt. A user reachable on the inbox alone would read it days
     * later, when it is no longer true.
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

    /**
     * @return list<class-string>
     */
    private function outboundChannelsFor(User $user): array
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
