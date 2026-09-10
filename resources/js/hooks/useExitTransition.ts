import { useEffect, useRef, useState } from 'react';

/**
 * Keeps a closing element mounted for `exitMs` so its CSS exit animation can
 * run — the job `AnimatePresence` used to do, without the animation engine.
 * Returns whether to render at all, and whether the element is on its way out
 * (put `data-closing` on it; the attribute reverses the entrance keyframe).
 */
export function useExitTransition(
    open: boolean,
    exitMs: number,
): { rendered: boolean; closing: boolean } {
    const [closing, setClosing] = useState(false);
    const wasOpenRef = useRef(open);

    if (wasOpenRef.current !== open) {
        wasOpenRef.current = open;
        setClosing(!open);
    }

    useEffect(() => {
        if (!closing) {
            return;
        }

        const timer = setTimeout(() => setClosing(false), exitMs);
        return () => clearTimeout(timer);
    }, [closing, exitMs]);

    return { rendered: open || closing, closing };
}
