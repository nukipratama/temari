import { Icon } from '@iconify/react';
import { useState } from 'react';
import PillButton from '@/components/ui/PillButton';
import DemoBlockedModal from '@/components/DemoBlockedModal';
import EnableNotificationsModal from '@/components/EnableNotificationsModal';
import { usePendingPost } from '@/hooks/usePendingPost';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { cooldownAriaLabel, useCooldownCountdown } from '@/hooks/useCooldownCountdown';
import { formatDurationHMS } from '@/lib/pace';

/**
 * The manual "Kirim notifikasi" pill shared by run/weekly/monthly recap
 * surfaces: force-pushes the Done narration at `url` and shows a spinner while
 * in flight. The push is channel-neutral — the server fans it out to every
 * channel the user has wired (Telegram if connected, web push if subscribed) —
 * so this button never names a channel. When the server reports a
 * `retryAfterSeconds` cooldown the button disables and shows a bare countdown
 * next to the paper-plane icon, so a re-send can't spam the user.
 *
 * A user with no channel wired (`reachable={false}`) still sees the pill, muted
 * — so the feature is discoverable instead of hidden. A tap opens the
 * {@see EnableNotificationsModal} nudge pointing at Pengaturan, the same for a
 * real user and the shared demo account (the demo-write modal only guards the
 * actual channel writes in Pengaturan, not this discovery surface).
 */
export default function SendNotificationButton({
    url,
    retryAfterSeconds,
    reachable = true,
}: Readonly<{ url: string; retryAfterSeconds?: number | null; reachable?: boolean }>) {
    const [sending, send] = usePendingPost(url, { preserveScroll: true });
    const { open, setOpen, guard } = useDemoGuard();
    const [enableOpen, setEnableOpen] = useState(false);
    const cooldownRemaining = useCooldownCountdown(retryAfterSeconds);
    const cooling = cooldownRemaining > 0;

    if (!reachable) {
        return (
            <>
                <PillButton
                    tone="outline"
                    size="sm"
                    className="opacity-60"
                    onClick={() => setEnableOpen(true)}
                    aria-label="Nyalain notifikasi dulu buat kirim"
                >
                    <Icon icon="mdi:send" width={15} height={15} aria-hidden />
                    Kirim notifikasi
                </PillButton>
                <EnableNotificationsModal open={enableOpen} onClose={() => setEnableOpen(false)} />
            </>
        );
    }

    let label = 'Kirim notifikasi';
    if (cooling) {
        label = formatDurationHMS(cooldownRemaining);
    } else if (sending) {
        label = 'Lagi ngirim…';
    }

    return (
        <>
            <PillButton
                tone="outline"
                size="sm"
                disabled={sending || cooling}
                className="disabled:opacity-60 disabled:cursor-not-allowed"
                onClick={() => guard(send)}
                aria-label={cooldownAriaLabel(cooldownRemaining, 'kirim notifikasi')}
            >
                <Icon
                    icon={sending ? 'mdi:loading' : 'mdi:send'}
                    width={15}
                    height={15}
                    className={sending ? 'animate-spin' : undefined}
                    aria-hidden
                />
                {label}
            </PillButton>
            <DemoBlockedModal open={open} onClose={() => setOpen(false)} />
        </>
    );
}
