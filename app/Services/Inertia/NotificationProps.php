<?php

declare(strict_types=1);

namespace App\Services\Inertia;

use App\Models\User;
use App\Services\Notifications\ChannelRouter;
use App\Support\SharedPropCacheKey;
use Closure;

/**
 * Per-channel reachability for the "Kirim notifikasi" affordances. The UI
 * combines the two into a single enabled/disabled decision, so they are
 * deliberately shipped as separate facts rather than one boolean.
 *
 * Every prop is returned as a closure, so Inertia skips the work entirely on a
 * partial reload that did not ask for that key.
 */
final readonly class NotificationProps
{
    public function __construct(private ChannelRouter $channels)
    {
    }

    /**
     * @return array<string, Closure>
     */
    public function forUser(?User $user): array
    {
        return [
            'telegramConnected' => fn (): bool => $this->telegramConnectedFor($user),
            'webPushSubscribed' => fn (): bool => $this->webPushSubscribedFor($user),
        ];
    }

    /**
     * Whether a "Kirim notifikasi" affordance can actually deliver over Telegram.
     *
     * This means wired **and** un-muted, not merely connected. A muted channel
     * would otherwise leave the button looking live while the send silently goes
     * nowhere — worse than the disabled state, which at least points at
     * Pengaturan.
     */
    private function telegramConnectedFor(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return SharedPropCacheKey::TelegramConnected->remember(
            $user->id,
            fn (): bool => $this->channels->telegramReachable($user),
        );
    }

    /**
     * Same for web push. Paired with {@see self::telegramConnectedFor()} so the
     * UI can enable the manual send whenever *any* channel can deliver.
     */
    private function webPushSubscribedFor(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return SharedPropCacheKey::WebPushSubscribed->remember(
            $user->id,
            fn (): bool => $this->channels->pushReachable($user),
        );
    }
}
