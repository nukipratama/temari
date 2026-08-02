import { useEffect, useState } from 'react';

import { formatDurationHMS } from '@/lib/pace';

/**
 * Counts an initial cooldown (in seconds) down to zero, ticking once a second
 * while positive. Resets whenever `initialSeconds` changes, so a fresh
 * retry-after value from the server restarts the countdown. Used by the analysis
 * "Baca ulang" / briefing footer buttons to disable retry until the cooldown
 * elapses.
 */
export function useCooldownCountdown(
    initialSeconds: number | null | undefined,
): number {
    const [remaining, setRemaining] = useState(() =>
        Math.max(0, initialSeconds ?? 0),
    );
    const [lastInitial, setLastInitial] = useState(initialSeconds);

    // Restart the countdown when a fresh retry-after value arrives — adjusted
    // during render (React-endorsed) rather than in an effect.
    if (initialSeconds !== lastInitial) {
        setLastInitial(initialSeconds);
        setRemaining(Math.max(0, initialSeconds ?? 0));
    }

    const ticking = remaining > 0;
    useEffect(() => {
        if (!ticking) return;
        const id = globalThis.setInterval(() => {
            setRemaining((r) => (r <= 1 ? 0 : r - 1));
        }, 1000);
        return () => globalThis.clearInterval(id);
    }, [ticking]);

    return remaining;
}

/**
 * aria-label for a button disabled by a {@link useCooldownCountdown}, or
 * undefined when not cooling. `action` is the Indonesian infinitive phrase for
 * what's being waited on (e.g. "baca ulang", "kirim ke Telegram").
 */
export function cooldownAriaLabel(
    remaining: number,
    action: string,
): string | undefined {
    return remaining > 0
        ? `Tunggu ${formatDurationHMS(remaining)} sebelum ${action}`
        : undefined;
}
