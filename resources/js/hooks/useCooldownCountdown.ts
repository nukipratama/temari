import { useEffect, useState } from 'react';

/**
 * Counts an initial cooldown (in seconds) down to zero, ticking once a second
 * while positive. Resets whenever `initialSeconds` changes, so a fresh
 * retry-after value from the server restarts the countdown. Used by the analysis
 * "Baca ulang" / briefing footer buttons to disable retry until the cooldown
 * elapses.
 */
export function useCooldownCountdown(initialSeconds: number | null | undefined): number {
    const [remaining, setRemaining] = useState(() => Math.max(0, initialSeconds ?? 0));

    useEffect(() => {
        setRemaining(Math.max(0, initialSeconds ?? 0));
    }, [initialSeconds]);

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
