import { useState } from 'react';

import DemoBlockedModal from '@/components/DemoBlockedModal';
import EnableNotificationsModal from '@/components/EnableNotificationsModal';
import { Icon } from '@/components/ui/Icon';
import {
    cooldownAriaLabel,
    useCooldownCountdown,
} from '@/hooks/useCooldownCountdown';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { usePendingPost } from '@/hooks/usePendingPost';
import { formatDurationHMS } from '@/lib/pace';

/**
 * The manual "send notification" control on the weekly and monthly recap cards:
 * force-pushes the Done narration at `url` and spins while in flight. The push
 * is channel-neutral — the server fans it out to every channel the user has
 * wired (Telegram if connected, web push if subscribed) — so this button never
 * names a channel.
 *
 * Drawn as the prototype's small circular bell inline with the recap's chip row
 * rather than a labelled pill, so the state that a label used to carry lives in
 * the glyph and the accessible name instead: cooling swaps to a clock and
 * disables, in-flight spins, and the countdown reads out of `aria-label` and
 * the tooltip.
 *
 * A user with no channel wired (`reachable={false}`) still sees the button,
 * muted — so the feature is discoverable instead of hidden. A tap opens the
 * {@see EnableNotificationsModal} nudge pointing at Settings, the same for a
 * real user and the shared demo account (the demo-write modal only guards the
 * actual channel writes in Settings, not this discovery surface).
 */
const BUTTON_CLASS =
    'focus-ring flex size-6 flex-none items-center justify-center rounded-full bg-muted text-icon-accent transition-colors hover:bg-accent disabled:cursor-not-allowed disabled:opacity-60';

export default function SendNotificationButton({
    url,
    retryAfterSeconds,
    reachable = true,
}: Readonly<{
    url: string;
    retryAfterSeconds?: number | null;
    reachable?: boolean;
}>) {
    const [sending, send] = usePendingPost(url, { preserveScroll: true });
    const { open, setOpen, guard } = useDemoGuard();
    const [enableOpen, setEnableOpen] = useState(false);
    const cooldownRemaining = useCooldownCountdown(retryAfterSeconds);
    const cooling = cooldownRemaining > 0;

    if (!reachable) {
        return (
            <>
                <button
                    type="button"
                    className={`${BUTTON_CLASS} opacity-60`}
                    onClick={() => setEnableOpen(true)}
                    title="turn on notifications to send"
                    aria-label="turn on notifications to send"
                >
                    <Icon
                        icon="mdi:bell-plus"
                        width={13}
                        height={13}
                        aria-hidden
                    />
                </button>
                <EnableNotificationsModal
                    open={enableOpen}
                    onClose={() => setEnableOpen(false)}
                />
            </>
        );
    }

    let title = 'send notification';
    if (cooling) {
        title = `next in ${formatDurationHMS(cooldownRemaining)}`;
    } else if (sending) {
        title = 'sending…';
    }

    let icon: 'mdi:loading' | 'mdi:clock-outline' | 'mdi:bell-plus' =
        'mdi:bell-plus';
    if (sending) {
        icon = 'mdi:loading';
    } else if (cooling) {
        icon = 'mdi:clock-outline';
    }

    return (
        <>
            <button
                type="button"
                className={BUTTON_CLASS}
                disabled={sending || cooling}
                onClick={() => guard(send)}
                title={title}
                aria-label={
                    cooldownAriaLabel(
                        cooldownRemaining,
                        'sending a notification',
                    ) ?? title
                }
            >
                <Icon
                    icon={icon}
                    width={13}
                    height={13}
                    className={sending ? 'animate-spin' : undefined}
                    aria-hidden
                />
            </button>
            <DemoBlockedModal open={open} onClose={() => setOpen(false)} />
        </>
    );
}
