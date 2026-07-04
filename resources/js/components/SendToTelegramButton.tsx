import { Icon } from '@iconify/react';
import { useState } from 'react';
import PillButton from '@/components/ui/PillButton';
import DemoBlockedModal from '@/components/DemoBlockedModal';
import ConnectTelegramModal from '@/components/ConnectTelegramModal';
import { usePendingPost } from '@/hooks/usePendingPost';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { cooldownAriaLabel, useCooldownCountdown } from '@/hooks/useCooldownCountdown';
import { formatDurationHMS } from '@/lib/pace';

/**
 * The manual "Kirim ke Telegram" pill shared by run/weekly/monthly recap
 * surfaces: force-pushes the Done narration at `url` and shows a spinner while
 * in flight. When the server reports a `retryAfterSeconds` cooldown the button
 * disables and shows a bare countdown next to the paper-plane icon, so a
 * re-send can't spam Telegram.
 *
 * A user who hasn't linked Telegram (`connected={false}`) still sees the pill,
 * muted — so the feature is discoverable instead of hidden. A tap opens the
 * {@see ConnectTelegramModal} nudge pointing at Profil, the same for a real
 * user and the shared demo account (the demo-write modal only guards the actual
 * connect/disconnect writes in Profil, not this discovery surface).
 */
export default function SendToTelegramButton({
    url,
    retryAfterSeconds,
    connected = true,
}: Readonly<{ url: string; retryAfterSeconds?: number | null; connected?: boolean }>) {
    const [sending, send] = usePendingPost(url, { preserveScroll: true });
    const { open, setOpen, guard } = useDemoGuard();
    const [connectOpen, setConnectOpen] = useState(false);
    const cooldownRemaining = useCooldownCountdown(retryAfterSeconds);
    const cooling = cooldownRemaining > 0;

    if (!connected) {
        return (
            <>
                <PillButton
                    tone="outline"
                    size="sm"
                    className="opacity-60"
                    onClick={() => setConnectOpen(true)}
                    aria-label="Sambungin Telegram dulu buat kirim ke Telegram"
                >
                    <Icon icon="mdi:send" width={15} height={15} aria-hidden />
                    Kirim ke Telegram
                </PillButton>
                <ConnectTelegramModal open={connectOpen} onClose={() => setConnectOpen(false)} />
            </>
        );
    }

    let label = 'Kirim ke Telegram';
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
                aria-label={cooldownAriaLabel(cooldownRemaining, 'kirim ke Telegram')}
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
