import { LoaderCircle, Send } from 'lucide-react';

import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import {
    cooldownAriaLabel,
    useCooldownCountdown,
} from '@/hooks/useCooldownCountdown';
import { usePendingPost } from '@/hooks/usePendingPost';
import { formatDurationHMS } from '@/lib/pace';

/**
 * "Send test notification" with the two states it was missing: in-flight, and
 * cooling. Without them a tap looked like nothing happened, and a second tap
 * either sent again or hit the route throttle as a bare 429.
 */
export default function TestSendButton({
    cooldownSeconds,
    guard,
}: Readonly<{
    cooldownSeconds: number | null;
    guard: (run: () => void) => void;
}>) {
    const [sending, send] = usePendingPost('/profile/notifications/test', {
        preserveScroll: true,
    });
    const remaining = useCooldownCountdown(cooldownSeconds);
    const cooling = remaining > 0;

    let label = 'send test notification';
    if (cooling) {
        label = formatDurationHMS(remaining);
    } else if (sending) {
        label = 'sending…';
    }

    return (
        <PillButton
            tone="outline"
            disabled={sending || cooling}
            className="w-full justify-center disabled:cursor-not-allowed disabled:opacity-60"
            onClick={() => guard(send)}
            aria-label={cooldownAriaLabel(
                remaining,
                'sending a test notification',
            )}
        >
            <Icon
                icon={sending ? LoaderCircle : Send}
                width={14}
                height={14}
                className={sending ? 'animate-spin' : undefined}
                aria-hidden
            />
            {label}
        </PillButton>
    );
}
